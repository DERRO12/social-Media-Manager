<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clients need a running trial or an active plan to use the workspace.
 * Without one they are sent to the billing page. Admins (and admins managing a client) always get in.
 */
class EnsureUserHasAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->hasAccess() && ! $request->session()->has('impersonator_id')) {
            return redirect()->route('billing')->with('error', $user->subscription?->status?->value === 'trialing'
                ? 'Your free trial has ended. Choose a plan to keep using your workspace.'
                : 'Your subscription is not active. Choose a plan to continue.');
        }

        return $next($request);
    }
}
