<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Lets the admin open a client's dashboard as that client, then return to the admin panel.
 */
class ImpersonationController extends Controller
{
    public function start(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_if($user->isAdmin(), 403, 'Cannot impersonate another admin.');

        $request->session()->put('impersonator_id', $request->user()->id);
        Auth::guard('web')->login($user);

        return redirect()->route('dashboard');
    }

    public function stop(Request $request): RedirectResponse
    {
        $adminId = $request->session()->pull('impersonator_id');
        $admin = $adminId ? User::find($adminId) : null;

        abort_unless($admin?->isAdmin(), 403);

        Auth::guard('web')->login($admin);

        return redirect()->route('admin.clients');
    }
}
