<?php

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Works out which organization the request belongs to (from the signed-in user)
 * and signs the user out if their organization has been suspended.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        Tenant::forget();

        $user = Auth::user();
        if (! $user) {
            return $next($request);
        }

        Tenant::set($user->organization_id);
        $organization = Tenant::current();

        if (! $organization || ! $organization->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            Tenant::forget();

            return redirect()->route('login')->withErrors(['organization' => 'This organization is not active. Please contact support.']);
        }

        // An ended trial is read-only: people can still sign in and look at their records
        if ($organization->trialExpired() && ! $request->isMethodSafe() && ! $request->routeIs('logout')) {
            return back()->withErrors(['trial' => 'Your free trial has ended, so records can no longer be changed. Please contact support to choose a plan.']);
        }

        return $next($request);
    }
}
