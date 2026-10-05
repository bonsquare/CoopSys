@extends('layouts.app')

@php $editing = $member->exists; @endphp
@section('title', $editing ? 'Edit Member' : 'Add Member')

@section('content')
<x-page-header :title="$editing ? 'Edit Member' : 'Add Member'" :subtitle="'Member No. ' . $nextNo">
    <a href="{{ $editing ? route('members.show', $member) : route('members.index') }}" class="btn btn-secondary">Cancel</a>
</x-page-header>

<x-card class="max-w-3xl">
    <form method="POST" action="{{ $editing ? route('members.update', $member) : route('members.store') }}" class="p-space-md grid grid-cols-1 md:grid-cols-2 gap-4">
        @csrf
        @if ($editing) @method('PUT') @endif

        <x-field label="First name" name="first_name" :value="$member->first_name" required />
        <x-field label="Last name" name="last_name" :value="$member->last_name" required />
        <x-select label="Gender" name="gender" :value="$member->gender" :options="['Male' => 'Male', 'Female' => 'Female']" placeholder="—" />
        <x-field label="Birthdate" name="birthdate" type="date" :value="$member->birthdate?->toDateString()" />
        <x-field label="Contact no." name="contact_no" :value="$member->contact_no" />
        <x-field label="Email" name="email" type="email" :value="$member->email" />
        <x-field label="Occupation" name="occupation" :value="$member->occupation" />
        <x-field label="Date joined" name="date_joined" type="date" :value="$member->date_joined?->toDateString()" required />
        <x-field label="Address" name="address" :value="$member->address" class="md:col-span-2" />

        <p class="md:col-span-2 field-hint">Share subscription is set on the Share Capital page.</p>

        <div class="md:col-span-2"><button class="btn btn-primary">{{ $editing ? 'Save changes' : 'Add member' }}</button></div>
    </form>
</x-card>
@endsection
