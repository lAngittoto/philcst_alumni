<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to ensure organizers:
 * 1. Are ACTIVE (not INACTIVE/SUSPENDED) — if not, force logout
 * 2. Have changed their password on first login
 */
class EnsureOrganizerPasswordChanged
{
    protected array $exceptRouteNames = [
        'logout',
        'organizer.change-password',
        'organizer.send-otp',
        'organizer.verify-otp',
        'organizer.confirm-password',
    ];

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

        // No organizer record → let through (will error downstream)
        if (!$organizer) {
            Log::warning("EnsureOrganizerPasswordChanged: No organizer record for user #{$user->id}");
            return $next($request);
        }

        // ── BLOCK INACTIVE ORGANIZERS ──────────────────────────────────────
        if (in_array($organizer->status, ['INACTIVE', 'SUSPENDED'])) {
            Log::info("EnsureOrganizerPasswordChanged: Blocking {$organizer->status} organizer #{$organizer->id}");
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login')
                ->withErrors(['email' => 'Your account has been deactivated. Please contact the administrator.']);
        }
        // ──────────────────────────────────────────────────────────────────

        // Password already changed → allow everything
        if ($organizer->password_changed_at !== null) {
            return $next($request);
        }

        // Password NOT yet changed — check if current route is exempt
        $routeName   = $request->route()?->getName();
        $currentPath = $request->path();

        if ($routeName && in_array($routeName, $this->exceptRouteNames)) {
            return $next($request);
        }

        foreach ($this->exceptPaths as $path) {
            if (str_starts_with($currentPath, $path)) {
                return $next($request);
            }
        }

        Log::info("EnsureOrganizerPasswordChanged: Redirecting organizer #{$organizer->id} to change-password (route: {$routeName})");
        return redirect()->route('organizer.change-password');
    }
}