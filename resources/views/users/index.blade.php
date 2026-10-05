@extends('layouts.app')

@section('title', 'Users')

@section('content')
<x-page-header title="User Management" subtitle="Staff accounts and what each role is allowed to do.">
    <a href="{{ route('users.create') }}" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">person_add</span> Add User</a>
</x-page-header>

<x-card>
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th class="no-print"></th></tr></thead>
            <tbody>
                @foreach ($users as $u)
                    <tr>
                        <td class="font-medium">{{ $u->name }} @if ($u->id === auth()->id()) <span class="text-[11px] text-outline">(you)</span> @endif</td>
                        <td>{{ $u->email }}</td>
                        <td>{{ $u->roleLabel() }}</td>
                        <td><span class="inline-flex px-2 py-0.5 rounded text-[11px] font-semibold {{ $u->is_active ? 'bg-secondary-fixed text-on-secondary-fixed' : 'bg-surface-container text-outline' }}">{{ $u->is_active ? 'Active' : 'Inactive' }}</span></td>
                        <td class="no-print whitespace-nowrap">
                            <a href="{{ route('users.edit', $u) }}" class="btn btn-secondary btn-sm">Edit</a>
                            @if ($u->id !== auth()->id())
                                <form method="POST" action="{{ route('users.toggle', $u) }}" class="inline" onsubmit="return confirm('Change this user\'s status?')">
                                    @csrf <button class="btn btn-sm {{ $u->is_active ? 'btn-danger' : 'btn-success' }}">{{ $u->is_active ? 'Deactivate' : 'Activate' }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-card>

<x-card title="What each role can do">
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr><th>Module</th>@foreach (config('coop.roles') as $label)<th class="text-center">{{ $label }}</th>@endforeach</tr>
            </thead>
            <tbody>
                @foreach (config('coop.permissions') as $module => $levels)
                    <tr>
                        <td class="font-medium">{{ ucfirst($module) }}</td>
                        @foreach (config('coop.roles') as $key => $label)
                            @php
                                $can = collect($levels)->map(fn ($roles, $level) => ($key === 'super_admin' || in_array($key, $roles)) ? $level : null)->filter()->values();
                            @endphp
                            <td class="text-center text-[11px]">{{ $can->isEmpty() ? '—' : $can->implode(', ') }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-card>
@endsection
