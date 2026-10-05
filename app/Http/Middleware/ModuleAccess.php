<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage in routes:  ->middleware('module:members,manage')
 * Stops users whose role is not allowed in that module (shows a 403 page).
 */
class ModuleAccess
{
    public function handle(Request $request, Closure $next, string $module, string $level = 'view'): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            auth()->logout();

            return redirect()->route('login')->withErrors(['email' => 'Your account is not active.']);
        }

        abort_unless($user->canAccess($module, $level), 403, 'Your role does not have access to this page.');

        return $next($request);
    }
}
