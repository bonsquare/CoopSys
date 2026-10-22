@extends('layouts.app')

@php
    $editing = $account->exists;
    $locked = $editing && ($account->is_system || $account->lines_count > 0);
@endphp

@section('title', $editing ? 'Edit Account' : 'Add Account')

@section('content')
<x-page-header :title="$editing ? 'Edit Account' : 'Add Account'">
    <a href="{{ route('accounting.accounts.index') }}" class="btn btn-secondary">Cancel</a>
</x-page-header>

@include('accounting._nav')

<x-card class="max-w-xl">
    <form method="POST" action="{{ $editing ? route('accounting.accounts.update', $account) : route('accounting.accounts.store') }}" class="p-space-md flex flex-col gap-4">
        @csrf
        @if ($editing) @method('PUT') @endif

        <x-field label="Account number" name="code" :value="$account->code" :required="$editing" :readonly="$locked" hint="{{ $locked ? 'The number of an account that is used or has entries cannot change.' : ($editing ? 'Assets start with 1, liabilities 2, equity 3, income 4, expenses 5.' : 'Leave blank and the system generates the next number for the type you pick.') }}" />
        <x-field label="Account name" name="name" :value="$account->name" required />
        <x-select label="Type" name="type" :value="$account->type" :options="\App\Models\Account::TYPES" required :disabled="$locked" />
        @if ($locked) <input type="hidden" name="type" value="{{ $account->type }}"> @endif
        <div>
            <label class="field-label" for="report_group">Report group</label>
            <input id="report_group" name="report_group" value="{{ old('report_group', $account->report_group) }}" list="group-list" maxlength="60" class="input" placeholder="{{ config('accounting.default_group.' . ($account->type ?: 'asset')) }}">
            <datalist id="group-list">
                @foreach (collect(config('accounting.groups'))->unique()->merge(\App\Models\Account::whereNotNull('report_group')->distinct()->pluck('report_group'))->unique() as $g) <option value="{{ $g }}"> @endforeach
            </datalist>
            <p class="field-hint">The section of the financial statements this account appears under. Accounts in the group "Cash and Bank" count as cash on the cash flow statement.</p>
            @error('report_group') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <x-field label="Description" name="description" :value="$account->description" />

        <div><button class="btn btn-primary">{{ $editing ? 'Save changes' : 'Add account' }}</button></div>
    </form>
</x-card>
@endsection
