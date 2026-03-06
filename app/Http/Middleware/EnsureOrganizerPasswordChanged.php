<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to ensure organizers change their password on first login
 * 
 * Checks if:
 * 1. User is authenticated
 * 2. User is an organizer
 * 3. password_changed_at is NULL (not yet changed)
 * 
 * If all conditions are true, redirects to password change wizard
 * Allows access to specific routes (logout, change-password, password endpoints)
 */
class EnsureOrganizerPasswordChanged
{
    /**
     * Routes that are allowed even if password not changed
     */
    protected $except = [
        'logout',
        'organizer.change-password',
        'organizer.send-otp',
        'organizer.verify-otp',
        'organizer.confirm-password',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Get authenticated user
        $user = auth()->user();
        
        // Only apply to authenticated organizers
        if (!$user || $user->role !== 'organizer') {
            Log::info('EnsureOrganizerPasswordChanged: User not organizer or not authenticated');
            return $next($request);
        }

        // Get organizer record
        $organizer = $user->organizer;
        
        Log::info('EnsureOrganizerPasswordChanged: Checking organizer', [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'has_organizer' => $organizer ? 'yes' : 'no',
            'organizer_id' => $organizer?->id,
            'password_changed_at' => $organizer?->password_changed_at,
            'current_route' => $request->route()?->getName(),
            'current_path' => $request->path(),
        ]);
        
        // If organizer doesn't exist, allow through
        if (!$organizer) {
            Log::warning('EnsureOrganizerPasswordChanged: Organizer record not found for user: ' . $user->id);
            return $next($request);
        }

        // If password_changed_at is NULL, password not yet changed
        // Redirect to change password page unless in allowed routes
        if ($organizer->password_changed_at === null) {
            // Check if current route is in the exception list
            $currentRoute = $request->route()?->getName();
            
            Log::info('EnsureOrganizerPasswordChanged: Password not changed yet', [
                'current_route' => $currentRoute,
                'is_exception' => in_array($currentRoute, $this->except) ? 'yes' : 'no',
            ]);
            
            // If route name is not in exceptions, redirect to password change
            if (!in_array($currentRoute, $this->except)) {
                Log::info('EnsureOrganizerPasswordChanged: Redirecting to password change');
                return redirect()->route('organizer.change-password');
            }
        } else {
            Log::info('EnsureOrganizerPasswordChanged: Password already changed at: ' . $organizer->password_changed_at);
        }

        return $next($request);
    }
}