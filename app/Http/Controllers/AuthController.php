<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'organization' => 'required|string|max:30',
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Same message for every failure, so nobody can probe which Organization IDs exist
        $failed = fn () => back()->withInput($request->only('organization', 'email'))
            ->withErrors(['email' => 'Wrong Organization ID, email or password, or the account is inactive.']);

        $organization = Organization::findByCode($credentials['organization']);
        if (! $organization) {
            return $failed();
        }
        if (! $organization->isActive()) {
            return back()->withInput($request->only('organization', 'email'))
                ->withErrors(['organization' => 'This organization is not active. Please contact support.']);
        }

        // Only active users of that organization may sign in
        $ok = Auth::attempt([
            'organization_id' => $organization->id,
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'is_active' => true,
        ], $request->boolean('remember'));

        if (! $ok) {
            return $failed();
        }

        $request->session()->regenerate();
        Cookie::queue('coop_org', $organization->code, 60 * 24 * 365);   // pre-fills the Organization ID next time
        AuditLog::record('login', 'Signed in');

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        AuditLog::record('logout', 'Signed out');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /** "My account" page: change own name and password. */
    public function editAccount()
    {
        return view('account.edit', ['user' => auth()->user()]);
    }

    public function updateAccount(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'current_password' => 'nullable|required_with:password',
            'password' => ['nullable', 'confirmed', Password::min(8)],
        ]);

        if (! empty($data['password'])) {
            if (! Hash::check($data['current_password'], $user->password)) {
                return back()->withErrors(['current_password' => 'Current password is incorrect.']);
            }
            $user->password = $data['password'];
        }

        $user->name = $data['name'];
        $user->save();
        AuditLog::record('account.updated', 'Updated own account');

        return back()->with('status', 'Your account was updated.');
    }
}
