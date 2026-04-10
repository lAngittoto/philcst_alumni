<?php

namespace App\Http\Controllers;

use App\Models\Alumni;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AlumniInformationController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // Show the alumni information form
    // ─────────────────────────────────────────────────────────────────────────

    public function show(): \Illuminate\View\View
    {
        // FIX: Always use a fresh direct DB query — never auth()->user()->alumni
        // (Eloquent cached relation can return stale data after the wizard
        //  completes and updates password_changed_at / status in the same session)
        $alumni = Alumni::where('user_id', auth()->id())->first();

        abort_unless($alumni, 404, 'Alumni record not found.');

        return view('alumni.alumni-information-wrapper', compact('alumni'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Save / update profile information
    // All profile fields live directly in the alumni table.
    // ─────────────────────────────────────────────────────────────────────────

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        // FIX: fresh direct query — not auth()->user()->alumni
        $alumni = Alumni::where('user_id', auth()->id())->first();

        abort_unless($alumni, 404, 'Alumni record not found.');

        $validated = $request->validate([
            'gender'               => 'required|string|in:Male,Female,Prefer not to say',
            'date_of_birth'        => 'required|date|before:today',
            'place_of_birth'       => 'required|string|max:255',
            'citizenship'          => 'required|string|max:100',
            'civil_status'         => 'required|string|in:Single,Married,Widowed,Separated,Annulled',
            'blood_type'           => 'nullable|string|max:10',
            'contact_number'       => 'required|string|max:20',
            'father_name'          => 'required|string|max:255',
            'mother_name'          => 'required|string|max:255',
            'spouse_name'          => 'nullable|string|max:255',
            'address_no'           => 'nullable|string|max:50',
            'address_street'       => 'required|string|max:255',
            'address_barangay'     => 'required|string|max:255',
            'address_municipality' => 'required|string|max:255',
            'address_province'     => 'required|string|max:255',
            'address_zip_code'     => 'required|string|max:10',
        ], [
            'gender.required'               => 'Please select your gender.',
            'gender.in'                     => 'Please select a valid gender option.',
            'date_of_birth.required'        => 'Date of birth is required.',
            'date_of_birth.before'          => 'Date of birth must be in the past.',
            'place_of_birth.required'       => 'Place of birth is required.',
            'citizenship.required'          => 'Citizenship is required.',
            'civil_status.required'         => 'Please select your civil status.',
            'civil_status.in'               => 'Please select a valid civil status.',
            'contact_number.required'       => 'Contact number is required.',
            'father_name.required'          => "Father's name is required.",
            'mother_name.required'          => "Mother's name is required.",
            'address_street.required'       => 'Street address is required.',
            'address_barangay.required'     => 'Barangay is required.',
            'address_municipality.required' => 'Municipality/City is required.',
            'address_province.required'     => 'Province is required.',
            'address_zip_code.required'     => 'Zip code is required.',
        ]);

        try {
            // Step 1: Persist all validated profile fields onto the alumni record
            $alumni->update($validated);

            // Step 2: Re-fetch fresh from DB to get the actual current state
            // FIX: After update(), Eloquent's in-memory model may still have
            //      old values for fields not included in $validated (like
            //      profile_completed). A refresh() ensures we see the real DB state.
            $alumni->refresh();

            // Step 3: Check completeness using actual field values (NOT the
            //         profile_completed flag — that would create a circular
            //         dependency since the flag is what we're about to set).
            //
            // FIX: The old code called $alumni->isProfileComplete() which
            //      required $this->profile_completed === true as a prerequisite.
            //      This meant profile_completed could NEVER become true because
            //      isProfileComplete() always returned false (the flag was false),
            //      so the controller always saved profile_completed = false.
            //
            //      After fixing isProfileComplete() in the Alumni model to check
            //      only actual data fields (no profile_completed prerequisite),
            //      this now works correctly.
            $profileComplete = $alumni->isProfileComplete();

            // Step 4: Persist the completion flag
            $alumni->update(['profile_completed' => $profileComplete]);

            Log::info(
                "AlumniInformationController: profile saved for alumni #{$alumni->id} "
                . "| complete: " . ($profileComplete ? 'yes' : 'no')
            );

            // Step 5: Redirect appropriately
            if ($profileComplete) {
                // Profile is fully complete — send to dashboard
                // The EnsureAlumniOnboarded middleware will allow through since
                // both Gate 1 (password_changed_at set) and Gate 2 (isProfileComplete)
                // are satisfied.
                return redirect()
                    ->route('alumni.dashboard')
                    ->with('success', '✅ Profile saved! Welcome to your dashboard.');
            }

            // Profile still incomplete — stay on the information page
            return back()
                ->withInput()
                ->with('warning', 'Progress saved. Please fill in all required fields to unlock the dashboard.');

        } catch (\Exception $e) {
            Log::error('AlumniInformationController update error: ' . $e->getMessage());
            return back()
                ->withInput()
                ->with('error', 'Failed to save profile. Please try again.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Redirect to dashboard — only allowed when profile is complete
    // ─────────────────────────────────────────────────────────────────────────

    public function dashboard(): \Illuminate\Http\RedirectResponse
    {
        // FIX: fresh query
        $alumni = Alumni::where('user_id', auth()->id())->first();

        if ($alumni && $alumni->isProfileComplete()) {
            return redirect()->route('alumni.dashboard');
        }

        return back()->with('error', 'Please complete all required fields before accessing the dashboard.');
    }
}