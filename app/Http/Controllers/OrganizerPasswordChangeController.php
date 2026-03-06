<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to ensure organizers change their password on first login.
 *
 * Checks if:
 * 1. User is authenticated
 * 2. User has role === 'organizer'
 * 3. password_changed_at is NULL on the organizer record
 *
 * If all true → redirect to password change wizard
 * Allows specific routes through even if password not changed
 */
class EnsureOrganizerPasswordChanged
{
    /**
     * Route NAMES that bypass this middleware.
     * These must match the names defined in routes/web.php exactly.
     */
    protected array $exceptRouteNames = [
        'logout',
        'organizer.change-password',
        'organizer.send-otp',
        'organizer.verify-otp',
        'organizer.confirm-password',
    ];

    /**
     * URL path prefixes that bypass this middleware (fallback for unnamed routes).
     */
    protected array $exceptPaths = [
        'organizer/change-password',
        'organizer/password',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        // Only enforce for authenticated organizers
        if (!$user || $user->role !== 'organizer') {
            return $next($request);
        }

        $organizer = $user->organizer;

        // No organizer record → let through (will show error downstream)
        if (!$organizer) {
            Log::warning("EnsureOrganizerPasswordChanged: No organizer record for user #{$user->id}");
            return $next($request);
        }

        // Password already set → allow everything
        if ($organizer->password_changed_at !== null) {
            return $next($request);
        }

        // Password NOT yet changed — check if current route is exempt
        $routeName   = $request->route()?->getName();
        $currentPath = $request->path();

        // Check exempt route names
        if ($routeName && in_array($routeName, $this->exceptRouteNames)) {
            return $next($request);
        }

        // Check exempt path prefixes (fallback)
        foreach ($this->exceptPaths as $path) {
            if (str_starts_with($currentPath, $path)) {
                return $next($request);
            }
        }

        // Block and redirect to password change wizard
        Log::info("EnsureOrganizerPasswordChanged: Redirecting organizer #{$organizer->id} to change-password (route: {$routeName})");

        return redirect()->route('organizer.change-password');
    }
}