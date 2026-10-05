<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\OrganizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/** Self-service sign-up: a new organization and its first administrator. */
class RegistrationController extends Controller
{
    public function create()
    {
        abort_unless(config('coop.registration'), 404);

        return view('auth.register');
    }

    public function store(Request $request, OrganizationService $organizations)
    {
        abort_unless(config('coop.registration'), 404);

        $data = $request->validate([
            'organization_name' => 'required|string|max:120',
            'organization_email' => 'nullable|email|max:120',
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:120',
            'password' => ['required', 'confirmed', Password::min(8)],
            'terms' => 'accepted',
        ], ['terms.accepted' => 'Please accept the terms of use to continue.']);

        [$org, $admin] = $organizations->provision(
            ['name' => $data['organization_name'], 'email' => $data['organization_email'] ?? $data['email']],
            ['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']],
        );

        Auth::login($admin);
        $request->session()->regenerate();
        AuditLog::record('organization.created', "Organization {$org->code} registered");

        return redirect()->route('dashboard')->with('status', "Welcome! Your Organization ID is {$org->code}. Keep it safe, because everyone on your team needs it to sign in.");
    }
}
