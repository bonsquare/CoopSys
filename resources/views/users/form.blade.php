@extends('layouts.app')

@php $editing = $user->exists; @endphp
@section('title', $editing ? 'Edit User' : 'Add User')

@section('content')
<x-page-header :title="$editing ? 'Edit User' : 'Add User'">
    <a href="{{ route('users.index') }}" class="btn btn-secondary">Cancel</a>
</x-page-header>

<x-card class="max-w-xl">
    <form method="POST" action="{{ $editing ? route('users.update', $user) : route('users.store') }}" class="p-space-md flex flex-col gap-4">
        @csrf
        @if ($editing) @method('PUT') @endif

        <x-field label="Full name" name="name" :value="$user->name" required />
        <x-field label="Email (used to sign in)" name="email" type="email" :value="$user->email" required />
        <x-select label="Role" name="role" :options="config('coop.roles')" :value="$user->role" required />
        <x-field :label="$editing ? 'New password (leave blank to keep)' : 'Password'" name="password" type="password" :required="! $editing" hint="At least 8 characters." />
        <x-field label="Confirm password" name="password_confirmation" type="password" />

        <div><button class="btn btn-primary">{{ $editing ? 'Save changes' : 'Create user' }}</button></div>
    </form>
</x-card>
@endsection
