<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizerPasswordChanged
{
    protected array $exceptRouteNames = [
        'logout',
        'login',
        'organizer.change-password',
    ];

    protected array $exceptPaths = [
        'organizer/change-password',
        'login',
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

        // No organizer record → let through
        if (!$organizer) {
            Log::warning("EnsureOrganizerPasswordChanged: No organizer record for user #{$user->id}");
            return $next($request);
        }

        // ── BLOCK INACTIVE / SUSPENDED ────────────────────────────────────
        if (in_array($organizer->status, ['INACTIVE', 'SUSPENDED'])) {
            Log::info("EnsureOrganizerPasswordChanged: Blocking {$organizer->status} organizer #{$organizer->id}");
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login')
                ->withErrors(['email' => 'Your account has been deactivated. Please contact the administrator.']);
        }

        // Password already changed → allow everything
        if ($organizer->password_changed_at !== null) {
            return $next($request);
        }

        // ── CHECK EXEMPT ROUTES ───────────────────────────────────────────
        $routeName   = $request->route()?->getName();
        $currentPath = $request->path();

        if ($routeName && in_array($routeName, $this->exceptRouteNames)) {
            return $next($request);
        }

        foreach ($this->exceptPaths as $path) {
            if (str_starts_with($currentPath, ltrim($path, '/'))) {
                return $next($request);
            }
        }

        // ── ONLY REDIRECT IF FRESHLY AUTHENTICATED ────────────────────────
        // If flag is NOT set → user got here via browser-back or direct URL,
        // NOT via a fresh login → force logout and send to login page.
        if (!session()->has('organizer_requires_password_change')) {
            Log::info("EnsureOrganizerPasswordChanged: No fresh-login flag for organizer #{$organizer->id}, forcing logout.");
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login');
        }

        // Fresh login + password not changed → redirect to wizard
        Log::info("EnsureOrganizerPasswordChanged: Redirecting organizer #{$organizer->id} to change-password.");
        return redirect()->route('organizer.change-password');
    }
}