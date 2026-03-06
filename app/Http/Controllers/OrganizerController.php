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
    /**
     * Create a new organizer account with temporary password
     * This is typically called by admin/system
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users|unique:organizer',
            'id_number' => 'required|string|unique:organizer',
            'department' => 'required|string|max:255',
        ]);

        try {
            // Generate temporary password
            $tempPassword = $this->generateTemporaryPassword();

            // Create user account
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'role' => 'organizer',
                'password' => Hash::make($tempPassword),
            ]);

            // Create organizer record
            // NOTE: password_changed_at is NULL, which triggers first-time password change
            $organizer = Organizer::create([
                'user_id' => $user->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'id_number' => $validated['id_number'],
                'department' => $validated['department'],
                'status' => 'ACTIVE',
                'password_changed_at' => null, // IMPORTANT: Triggers first-time password change on login
            ]);

            // Send welcome email with temporary credentials
            try {
                Mail::to($organizer->email)->send(new OrganizerRegistered($organizer, $tempPassword));
                Log::info('Welcome email sent to organizer: ' . $organizer->email);
            } catch (\Exception $mailError) {
                Log::warning('Failed to send welcome email to ' . $organizer->email . ': ' . $mailError->getMessage());
                // Continue anyway - account is created
            }

            Log::info('Organizer account created: ' . $organizer->email . ' (ID: ' . $organizer->id_number . ')');

            return response()->json([
                'success' => true,
                'message' => 'Organizer account created successfully. Welcome email sent.',
                'organizer' => $organizer,
                'tempPassword' => $tempPassword, // For display in admin panel only
            ]);

        } catch (\Exception $e) {
            Log::error('Error creating organizer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create organizer account: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate a temporary password
     * Format: Random combination of uppercase, lowercase, numbers, and special characters
     * Example: 9NYgfcYFRn
     */
    private function generateTemporaryPassword(): string
    {
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $special = '@$!%*?&';

        $password = '';
        
        // Ensure at least one of each required character type
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];

        // Fill the rest with random characters from all types
        $allChars = $uppercase . $lowercase . $numbers . $special;
        for ($i = 4; $i < 10; $i++) {
            $password .= $allChars[random_int(0, strlen($allChars) - 1)];
        }

        // Shuffle to avoid predictable pattern
        return str_shuffle($password);
    }

    /**
     * Get organizer profile
     */
    public function show(Organizer $organizer)
    {
        return response()->json($organizer);
    }

    /**
     * Update organizer profile (non-password fields)
     */
    public function update(Request $request, Organizer $organizer)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'department' => 'sometimes|string|max:255',
            'profile_photo' => 'sometimes|image|max:2048',
        ]);

        try {
            if ($request->hasFile('profile_photo')) {
                $path = $request->file('profile_photo')->store('organizer-photos');
                $validated['profile_photo'] = $path;
            }

            $organizer->update($validated);

            Log::info('Organizer profile updated: ' . $organizer->email);

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
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

    /**
     * Change organizer status (admin only)
     */
    public function updateStatus(Request $request, Organizer $organizer)
    {
        $validated = $request->validate([
            'status' => 'required|in:ACTIVE,INACTIVE,SUSPENDED',
        ]);

        try {
            $organizer->update(['status' => $validated['status']]);

            Log::info('Organizer status updated: ' . $organizer->email . ' -> ' . $validated['status']);

            return response()->json([
                'success' => true,
                'message' => 'Status updated successfully',
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

    /**
     * Delete organizer account (admin only)
     */
    public function destroy(Organizer $organizer)
    {
        try {
            $email = $organizer->email;
            
            // Delete associated user account
            if ($organizer->user) {
                $organizer->user->delete();
            }
            
            // Delete organizer record
            $organizer->delete();

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