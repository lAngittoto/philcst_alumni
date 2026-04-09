<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureAlumniPasswordChanged
 *
 * SECURITY RULE:
 *   needsAccountSetup() is the ONLY gate — not session flags.
 *   Session flags are just routing helpers.
 *
 * If needsAccountSetup() → true  : alumni MUST go through wizard
 * If needsAccountSetup() → false : alumni may access anything
 *
 * A PENDING alumni (status=PENDING OR no real email OR still has temp password)
 * is ALWAYS redirected to the wizard — never silently logged out, never bypassed.
 */
class EnsureAlumniPasswordChanged
{
    /**
     * Routes a PENDING alumni is allowed to visit.
     * Includes the wizard view, all wizard POST actions, and global exemptions.
     */
    protected array $allowedRouteNames = [
        // Global
        'login',
        'logout',

        // Wizard — GET view (Volt)
        'alumni.change-password',

        // Wizard — POST actions (AlumniPasswordChangeController, kept for safety)
        'alumni.send-otp',
        'alumni.resend-otp',
        'alumni.verify-otp',
        'alumni.confirm-password',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        // ── Not authenticated or not an alumni → pass through ─────────────────
        if (!$user || $user->role !== 'alumni') {
            return $next($request);
        }

        $alumni = $user->alumni;

        // ── No alumni record → let downstream handle it ───────────────────────
        if (!$alumni) {
            Log::warning("EnsureAlumniPasswordChanged: No alumni record for user #{$user->id}");
            return $next($request);
        }

        // ── Account setup COMPLETE → clean up stale wizard keys & allow ───────
        if (!$alumni->needsAccountSetup()) {
            $this->clearWizardSession();
            return $next($request);
        }

        // ══════════════════════════════════════════════════════════════════════
        // Account setup is INCOMPLETE (PENDING status, no real email, or still
        // on temp password). The alumni MUST complete the wizard.
        // ══════════════════════════════════════════════════════════════════════

        $routeName = $request->route()?->getName();

        // ── Livewire AJAX endpoints → always pass through ─────────────────────
        // Livewire handles its own component-level auth; blocking these would
        // break the wizard's internal wire:click / wire:model calls.
        if ($this->isLivewireRequest($request)) {
            return $next($request);
        }

        // ── Allowed wizard / exempt routes → pass through & guarantee flag ────
        if ($routeName && in_array($routeName, $this->allowedRouteNames)) {
            // Always guarantee the session flag while on wizard routes so the
            // Volt component can rely on it for step restoration.
            session()->put('alumni_requires_password_change', true);
            Log::info("EnsureAlumniPasswordChanged: PENDING alumni #{$alumni->id} allowed on '{$routeName}'");
            return $next($request);
        }

        // ── Any other route while setup is incomplete → redirect to wizard ─────
        // NOTE: We do NOT log the user out here. We just redirect. Logging out
        // was the old behaviour that caused an infinite loop when the session
        // flag was missing (e.g. after a session regeneration).
        Log::info("EnsureAlumniPasswordChanged: PENDING alumni #{$alumni->id} blocked from '{$routeName}', redirecting to wizard.");
        session()->put('alumni_requires_password_change', true);
        return redirect()->route('alumni.change-password');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns true for any Livewire internal HTTP request so that the wizard
     * Volt component's AJAX actions are never blocked by this middleware.
     */
    private function isLivewireRequest(Request $request): bool
    {
        return $request->is('livewire/*')
            || $request->hasHeader('X-Livewire')
            || str_contains($request->getRequestUri(), '/livewire/');
    }

    /**
     * Remove all wizard session keys once the alumni has finished setup.
     */
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