<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureDirectorPasswordChanged
{
    protected array $passwordChangeRoutes = [
        'director.change-password',
    ];

    protected array $exceptRouteNames = [
        'logout',
        'login',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        // Not authenticated or not a director → let through
        if (!$user || $user->role !== 'director') {
            return $next($request);
        }

        $director = DB::table('director')->where('user_id', $user->id)->first();

        // No director record → let through (will error downstream)
        if (!$director) {
            Log::warning("EnsureDirectorPasswordChanged: No director record for user #{$user->id}");
            return $next($request);
        }

        // ─── CHECK IF ACCOUNT IS INACTIVE ────────────────────────────────────
        if (in_array($director->status, ['INACTIVE', 'SUSPENDED'])) {
            Log::info("EnsureDirectorPasswordChanged: Blocking {$director->status} director #{$director->id}");
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login')
                ->withErrors(['email' => 'Your account has been deactivated. Please contact the administrator.']);
        }

        // Password already changed → allow all routes
        if ($director->password_changed_at !== null) {
            return $next($request);
        }

        // ─── PASSWORD NOT YET CHANGED ─────────────────────────────────────────
        $routeName = $request->route()?->getName();

        // Allow exempt routes (login, logout)
        if ($routeName && in_array($routeName, $this->exceptRouteNames)) {
            return $next($request);
        }

        $isPasswordChangeRoute = $routeName && in_array($routeName, $this->passwordChangeRoutes);

        // If accessing change-password page → require fresh-login flag
        if ($isPasswordChangeRoute) {
            if (!session()->has('director_requires_password_change')) {
                Log::info("EnsureDirectorPasswordChanged: No fresh-login flag for director #{$director->id}, forcing logout.");
                auth()->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return redirect()->route('login');
            }
            return $next($request);
        }

        // Trying to access other routes → redirect to change-password
        Log::info("EnsureDirectorPasswordChanged: Redirecting director #{$director->id} to change-password (route: {$routeName})");
        return redirect()->route('director.change-password');
    }
}