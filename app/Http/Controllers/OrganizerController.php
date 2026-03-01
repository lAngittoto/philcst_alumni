<?php

namespace App\Http\Controllers;

use App\Models\Organizer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class OrganizerController extends Controller
{
    /**
     * Show organizers management page
     */
    public function index()
    {
        return view('organizers.management');
    }

    /**
     * Store new organizer
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:organizer,email', 'unique:users,email'],
            'id_number' => ['required', 'string', 'unique:organizer,id_number'],
            'department' => ['required', 'string'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
        ]);

        try {
            // Store photo if provided
            $photoPath = 'organizers/default.png';
            if ($request->hasFile('photo')) {
                $file = $request->file('photo');
                $filename = 'organizer-' . \Illuminate\Support\Str::uuid() . '.' . $file->getClientOriginalExtension();
                $file->storeAs('organizers', $filename, 'public');
                $photoPath = 'organizers/' . $filename;
            }

            // Create user account
            $password = \Illuminate\Support\Str::random(10);
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($password),
                'role' => 'organizer',
            ]);

            // Create organizer record
            $organizer = Organizer::create([
                'user_id' => $user->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'id_number' => $validated['id_number'],
                'department' => strtoupper($validated['department']),
                'profile_photo' => $photoPath,
                'status' => 'ACTIVE',
            ]);

            return redirect()->back()->with('success', "Organizer '{$organizer->name}' registered successfully!");
        } catch (\Exception $e) {
            Log::error('Organizer creation failed: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to register organizer: ' . $e->getMessage());
        }
    }

    /**
     * Update organizer
     */
    public function update(Request $request, $id)
    {
        try {
            $organizer = Organizer::findOrFail($id);

            $validated = $request->validate([
                'name' => ['sometimes', 'string', 'max:255'],
                'email' => ['sometimes', 'email', 'unique:organizer,email,' . $id],
                'department' => ['sometimes', 'string'],
                'status' => ['sometimes', 'in:ACTIVE,INACTIVE,SUSPENDED'],
            ]);

            $organizer->update($validated);

            return redirect()->back()->with('success', 'Organizer updated successfully!');
        } catch (\Exception $e) {
            Log::error('Organizer update failed: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to update organizer');
        }
    }

    /**
     * Delete organizer
     */
    public function destroy($id)
    {
        try {
            $organizer = Organizer::findOrFail($id);
            
            // Delete photo if not default
            if ($organizer->profile_photo && strpos($organizer->profile_photo, 'default.png') === false) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($organizer->profile_photo);
            }

            // Delete associated user
            if ($organizer->user) {
                $organizer->user()->delete();
            }

            $organizer->delete();

            return redirect()->back()->with('success', 'Organizer deleted successfully!');
        } catch (\Exception $e) {
            Log::error('Organizer deletion failed: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to delete organizer');
        }
    }
}