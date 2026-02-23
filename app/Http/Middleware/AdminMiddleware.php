<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
// app/Http/Middleware/AdminMiddleware.php

public function handle(Request $request, Closure $next): Response
{
    /** @var \App\Models\User $user */
    $user = Auth::user();

    if (!Auth::check() || !$user->isAdmin()) {
        Auth::logout();
        return redirect()->route('login')
                         ->withErrors(['invalid' => 'Unauthorized access.']);
    }

    return $next($request);
}
}