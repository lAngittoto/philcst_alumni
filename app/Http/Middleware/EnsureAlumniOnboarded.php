<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\Alumni;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureAlumniOnboarded
 *
 * GATE 1 — Account setup (password wizard)
 *   needsAccountSetup() true  → must complete wizard first
 *   needsAccountSetup() false → proceeds to Gate 2
 *
 * GATE 2 — Profile completion
 *   profile_completed false → must fill alumni.information
 *   profile_completed true  → allowed through
 *
 * KEY FIX:
 *   Gate 1 NO LONGER has a Livewire/wire:navigate bypass.
 *   wire:navigate sends an X-Livewire header which was previously
 *   treated as a safe Livewire AJAX call — allowing alumni to
 *   skip the password wizard entirely by clicking sidebar links.
 *   Gate 1 now blocks ALL requests (including wire:navigate) until
 *   the wizard is complete.
 *
 *   Gate 2 keeps the Livewire bypass so the information page's
 *   own Livewire component can function while the form is open.
 */
class EnsureAlumniOnboarded
{
    /**
     * Routes allowed while account setup (Gate 1) is incomplete.
     * ONLY wizard pages and their POST/AJAX endpoints go here.
     */
    protected array $wizardRoutes = [
        'login',
        'logout',
        'alumni.change-password',
        'alumni.send-otp',
        'alumni.resend-otp',
        'alumni.verify-otp',
        'alumni.confirm-password',
    ];

    /**
     * Routes allowed while profile (Gate 2) is incomplete.
     * Includes both GET and Livewire update endpoints.
     */
    protected array $profileRoutes = [
        'alumni.information',
        'alumni.information.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        // Not authenticated or not an alumni → pass through
        if (!$user || $user->role !== 'alumni') {
            return $next($request);
        }

        // Always use a fresh DB query — never the cached Eloquent relation
        $alumni = Alumni::where('user_id', $user->id)->first();

        if (!$alumni) {
            Log::warning("EnsureAlumniOnboarded: No alumni record for user #{$user->id}");
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        // ══════════════════════════════════════════════════════════════════════
        // GATE 1 — Account Setup (wizard must be completed first)
        // ══════════════════════════════════════════════════════════════════════
        if ($alumni->needsAccountSetup()) {

            // Always allow logout so alumni is never completely trapped
            if ($routeName === 'logout') {
                return $next($request);
            }

            // Allow only the wizard-specific routes
            if ($routeName && in_array($routeName, $this->wizardRoutes)) {
                session()->put('alumni_requires_password_change', true);
                Log::info("EnsureAlumniOnboarded: PENDING alumni #{$alumni->id} allowed on wizard route '{$routeName}'");
                return $next($request);
            }

            // ── CRITICAL FIX ──────────────────────────────────────────────────
            // NO Livewire bypass here. wire:navigate sends X-Livewire headers
            // which the old code treated as safe, letting alumni skip the wizard
            // by clicking sidebar links. Gate 1 must block EVERYTHING else,
            // including wire:navigate and all Livewire AJAX calls from
            // non-wizard pages.
            // ─────────────────────────────────────────────────────────────────

            session()->put('alumni_requires_password_change', true);
            Log::info("EnsureAlumniOnboarded: PENDING alumni #{$alumni->id} blocked from '{$routeName}', redirecting to wizard.");

            // For wire:navigate / fetch requests, redirect normally —
            // Livewire handles the redirect on the client side.
            return redirect()->route('alumni.change-password');
        }

        // Wizard is done — clean up any leftover wizard session keys
        $this->clearWizardSession();

        // ══════════════════════════════════════════════════════════════════════
        // GATE 2 — Profile Completion
        // ══════════════════════════════════════════════════════════════════════
        if (!$alumni->profile_completed) {

            // Allow Livewire AJAX so the information page's own component works
            // (form saves, validation, etc. on the information page itself)
            if ($this->isLivewireRequest($request)) {
                return $next($request);
            }

            // Allow the information page (GET + Livewire update)
            if ($routeName && in_array($routeName, $this->profileRoutes)) {
                return $next($request);
            }

            // Always allow logout
            if ($routeName === 'logout') {
                return $next($request);
            }

            Log::info("EnsureAlumniOnboarded: alumni #{$alumni->id} profile incomplete, redirecting to information page.");
            return redirect()
                ->route('alumni.information')
                ->with('info', 'Please complete your profile information to access the portal.');
        }

        // Both gates passed → allow through
        return $next($request);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Detect Livewire AJAX component update requests.
     * NOTE: wire:navigate also sends X-Livewire headers — that is why
     * we intentionally do NOT call this helper in Gate 1.
     */
    private function isLivewireRequest(Request $request): bool
    {
        return $request->is('livewire/*')
            || $request->hasHeader('X-Livewire')
            || str_contains($request->getRequestUri(), '/livewire/');
    }

    private function clearWizardSession(): void
    {
        session()->forget([
            'alumni_requires_password_change',
            'alumni_pending_email',
            'alumni_pending_password',
            'alumni_password_reset_step',
        ]);
    }
}