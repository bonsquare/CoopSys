<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index()
    {
        return view('users.index', ['users' => User::inOrganization()->orderBy('name')->get()]);
    }

    public function create()
    {
        return view('users.form', ['user' => new User(['role' => 'staff', 'is_active' => true])]);
    }

    public function store(Request $request)
    {
        $limit = Tenant::current()?->limit('users');
        if ($limit !== null && User::inOrganization()->count() >= $limit) {
            return back()->withInput()->withErrors(['email' => "Your plan allows up to $limit users. Please upgrade your plan to add more."]);
        }

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => ['required', 'email', 'max:120', Tenant::unique('users', 'email')],
            'role' => ['required', Rule::in(array_keys(config('coop.roles')))],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::create($data + ['is_active' => true]);
        AuditLog::record('user.created', "{$user->email} as {$user->roleLabel()}");

        return redirect()->route('users.index')->with('status', "User {$user->name} created.");
    }

    public function edit(User $user)
    {
        return view('users.form', ['user' => $user]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => ['required', 'email', 'max:120', Tenant::unique('users', 'email', $user->id)],
            'role' => ['required', Rule::in(array_keys(config('coop.roles')))],
            'password' => ['nullable', 'confirmed', Password::min(8)],
        ]);

        if ($data['role'] !== 'super_admin' && $user->role === 'super_admin' && $this->lastSuperAdmin($user)) {
            throw ValidationException::withMessages(['role' => 'There must be at least one active Super Admin.']);
        }

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);
        AuditLog::record('user.updated', "{$user->email} ({$user->roleLabel()})");

        return redirect()->route('users.index')->with('status', "User {$user->name} updated.");
    }

    public function toggle(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->withErrors(['user' => 'You cannot deactivate your own account.']);
        }
        if ($user->is_active && $user->role === 'super_admin' && $this->lastSuperAdmin($user)) {
            return back()->withErrors(['user' => 'There must be at least one active Super Admin.']);
        }

        $user->update(['is_active' => ! $user->is_active]);
        AuditLog::record('user.status', $user->email.($user->is_active ? ' activated' : ' deactivated'));

        return back()->with('status', "{$user->name} is now ".($user->is_active ? 'active' : 'inactive').'.');
    }

    /** Is $user the only active Super Admin left? */
    private function lastSuperAdmin(User $user): bool
    {
        return User::inOrganization()->where('role', 'super_admin')->where('is_active', true)->where('id', '!=', $user->id)->doesntExist();
    }
}
