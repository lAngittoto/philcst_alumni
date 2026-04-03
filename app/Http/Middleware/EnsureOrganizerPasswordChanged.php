<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to ensure organizers change their password on first login.
 * 
 * Prevents back-button bypass by:
 * 1. Checking for fresh-login session flag
 * 2. Verifying password_changed_at is still NULL
 * 3. Forcing logout if user tries to bypass via direct URL
 */
class EnsureOrganizerPasswordChanged
{
    /**
     * Routes that bypass this middleware (must have fresh-login flag to pass)
     */
    protected array $passwordChangeRoutes = [
        'organizer.change-password',
    ];

    /**
     * Routes completely exempt from this middleware
     */
    protected array $exceptRouteNames = [
        'logout',
        'login',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        // Not authenticated or not an organizer → let through
        if (!$user || $user->role !== 'organizer') {
            return $next($request);
        }

        $organizer = $user->organizer;

        // No organizer record → let through (will error downstream)
        if (!$organizer) {
            Log::warning("EnsureOrganizerPasswordChanged: No organizer record for user #{$user->id}");
            return $next($request);
        }

        // ─── CHECK IF ACCOUNT IS INACTIVE/SUSPENDED ──────────────────────
        if (in_array($organizer->status, ['INACTIVE', 'SUSPENDED'])) {
            Log::info("EnsureOrganizerPasswordChanged: Blocking {$organizer->status} organizer #{$organizer->id}");
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login')
                ->withErrors(['email' => 'Your account has been deactivated. Please contact the administrator.']);
        }

        // Password already changed → allow all routes
        if ($organizer->password_changed_at !== null) {
            return $next($request);
        }

        // ─── PASSWORD NOT YET CHANGED ──────────────────────────────────────
        // At this point: password is NOT changed, user IS authenticated

        $routeName = $request->route()?->getName();

        // Allow completely exempt routes (login, logout)
        if ($routeName && in_array($routeName, $this->exceptRouteNames)) {
            return $next($request);
        }

        // Check if this is a password-change route
        $isPasswordChangeRoute = $routeName && in_array($routeName, $this->passwordChangeRoutes);

        // If accessing password change page → require fresh-login flag
        if ($isPasswordChangeRoute) {
            if (!session()->has('organizer_requires_password_change')) {
                Log::info("EnsureOrganizerPasswordChanged: No fresh-login flag for organizer #{$organizer->id}, forcing logout.");
                auth()->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return redirect()->route('login');
            }
            // Has fresh-login flag → let through
            return $next($request);
        }

        // ─── TRYING TO ACCESS OTHER ROUTES WITHOUT FRESH FLAG ──────────────
        // User is authenticated, password not changed, trying to access
        // something other than change-password, and no fresh-login flag
        Log::info("EnsureOrganizerPasswordChanged: Redirecting organizer #{$organizer->id} to change-password (route: {$routeName})");
        return redirect()->route('organizer.change-password');
    }
}