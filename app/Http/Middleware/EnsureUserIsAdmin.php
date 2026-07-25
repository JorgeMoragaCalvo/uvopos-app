<?php

namespace App\Http\Middleware;

use App\Providers\RouteServiceProvider;
use Closure;
use Illuminate\Http\Request;

/**
 * Restricts the staff page to users with role = 'admin'. Always runs
 * behind the `auth` middleware, so a guest is redirected to /login
 * before reaching here; a logged-in company user is sent back to their
 * own page rather than shown a 403.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user === null || !$user->isAdmin()) {
            return redirect(RouteServiceProvider::HOME);
        }

        return $next($request);
    }
}
