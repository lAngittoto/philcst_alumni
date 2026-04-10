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
 * Combines two sequential gates for alumni users:
 *
 * GATE 1 — Account setup (password + email wizard)
 *   needsAccountSetup() → true  : must complete wizard first
 *   needsAccountSetup() → false : proceeds to Gate 2
 *
 * GATE 2 — Profile completion (personal information form)
 *   isProfileComplete() → false : must fill up alumni.information
 *   isProfileComplete() → true  : allowed through to all routes
 *
 * FIX SUMMARY:
 *  - Always load alumni via direct DB query (never $user->alumni cached relation)
 *  - Gate 1 wizard routes: only the wizard pages are allowed while setup is pending
 *  - Gate 2 profile routes: information page is accessible while profile is incomplete
 *  - Neither gate logs the user out — only redirects
 */
class EnsureAlumniOnboarded
{
    /**
     * Routes allowed while account setup (Gate 1) is still incomplete.
     * ONLY the wizard itself and its POST endpoints go here.
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
     * Routes allowed while profile (Gate 2) is still incomplete.
     * Must include both the GET and PUT for the information page to
     * prevent a redirect loop.
     */
    protected array $profileRoutes = [
        'alumni.information',
        'alumni.information.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        // ── Not authenticated or not an alumni → pass through ─────────────────
        if (!$user || $user->role !== 'alumni') {
            return $next($request);
        }

        // ── FIX: Always use a fresh direct DB query ───────────────────────────
        // $user->alumni uses the Eloquent cached relation which can return a
        // stale instance (e.g., password_changed_at still null after the wizard
        // just set it). A direct query always reads the current DB state.
        $alumni = Alumni::where('user_id', $user->id)->first();

        // ── No alumni record → let downstream handle it ───────────────────────
        if (!$alumni) {
            Log::warning("EnsureAlumniOnboarded: No alumni record for user #{$user->id}");
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        // ══════════════════════════════════════════════════════════════════════
        // GATE 1 — Account setup (wizard)
        // ══════════════════════════════════════════════════════════════════════
        if ($alumni->needsAccountSetup()) {
            // Livewire AJAX must always pass — the wizard page uses it internally
            if ($this->isLivewireRequest($request)) {
                return $next($request);
            }

            // Allow only the wizard-specific routes
            if ($routeName && in_array($routeName, $this->wizardRoutes)) {
                session()->put('alumni_requires_password_change', true);
                Log::info("EnsureAlumniOnboarded: PENDING alumni #{$alumni->id} allowed on wizard route '{$routeName}'");
                return $next($request);
            }

            // Everything else (including alumni.information) is blocked until
            // the account setup wizard is completed.
            Log::info("EnsureAlumniOnboarded: PENDING alumni #{$alumni->id} blocked from '{$routeName}', redirecting to wizard.");
            session()->put('alumni_requires_password_change', true);
            return redirect()->route('alumni.change-password');
        }

        // ── Setup is done — clean up any leftover wizard session keys ─────────
        $this->clearWizardSession();

        // ══════════════════════════════════════════════════════════════════════
        // GATE 2 — Profile completion
        // ══════════════════════════════════════════════════════════════════════
        if (!$alumni->isProfileComplete()) {
            // Livewire AJAX passes through to prevent breaking any Livewire
            // components rendered on the information page itself.
            if ($this->isLivewireRequest($request)) {
                return $next($request);
            }

            // Allow the information page routes (GET + PUT) to prevent a loop
            if ($routeName && in_array($routeName, $this->profileRoutes)) {
                return $next($request);
            }

            // Also allow logout so a user is never completely trapped
            if ($routeName === 'logout') {
                return $next($request);
            }

            Log::info("EnsureAlumniOnboarded: alumni #{$alumni->id} profile incomplete, redirecting to information page.");
            return redirect()
                ->route('alumni.information')
                ->with('info', 'Please complete your profile information to access the portal.');
        }

        // ── Both gates passed → allow through to any route ───────────────────
        return $next($request);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

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