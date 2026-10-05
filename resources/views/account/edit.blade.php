@extends('layouts.app')

@section('title', 'My Account')

@section('content')
<x-page-header title="My Account" subtitle="Change your name or password." />

<x-card class="max-w-xl">
    <form method="POST" action="{{ route('account.update') }}" class="p-space-md flex flex-col gap-4">
        @csrf @method('PUT')
        <div class="text-sm text-on-surface-variant">{{ $user->email }} · {{ $user->roleLabel() }}</div>
        <x-field label="Full name" name="name" :value="$user->name" required />
        <x-field label="Current password" name="current_password" type="password" hint="Only needed if you want a new password." />
        <x-field label="New password" name="password" type="password" hint="At least 8 characters." />
        <x-field label="Confirm new password" name="password_confirmation" type="password" />
        <div><button class="btn btn-primary">Save changes</button></div>
    </form>
</x-card>
@endsection
