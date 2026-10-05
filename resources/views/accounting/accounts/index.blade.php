@extends('layouts.app')

@section('title', 'Chart of Accounts')

@section('content')
<x-page-header title="Chart of Accounts" subtitle="Every account in the books and its balance today.">
    @allowed('accounting', 'manage')
        <a href="{{ route('accounting.accounts.create') }}" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">add</span> Add Account</a>
    @endallowed
</x-page-header>

@include('accounting._nav')

<x-card>
    <form method="GET" class="p-space-md flex flex-wrap items-end gap-3 no-print">
        <x-select label="Type" name="type" :value="$type" :options="\App\Models\Account::TYPES" placeholder="All types" />
        <button class="btn btn-secondary">Filter</button>
        @if ($type) <a href="{{ route('accounting.accounts.index') }}" class="btn btn-secondary">Clear</a> @endif
    </form>

    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Code</th><th>Account</th><th>Type</th><th>Report group</th><th>Normal balance</th><th class="num">Balance</th><th>Status</th><th class="no-print"></th></tr></thead>
            <tbody>
                @foreach ($accounts as $a)
                    <tr class="{{ $a->is_active ? '' : 'opacity-50' }}">
                        <td class="font-mono font-semibold">{{ $a->code }}</td>
                        <td class="font-medium">{{ $a->name }}@if ($a->is_system) <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] bg-surface-container text-outline" title="Used for automatic postings">SYSTEM</span>@endif
                            @if ($a->description)<div class="text-[11px] text-outline">{{ $a->description }}</div>@endif</td>
                        <td>{{ \App\Models\Account::TYPES[$a->type] }}</td>
                        <td class="text-on-surface-variant">{{ $a->groupName() }}</td>
                        <td>{{ ucfirst($a->normalSide()) }}</td>
                        <td class="num font-semibold">@acct($a->balance)</td>
                        <td>{{ $a->is_active ? 'Active' : 'Inactive' }}</td>
                        <td class="no-print whitespace-nowrap">
                            <a href="{{ route('accounting.ledger', ['account' => $a->id]) }}" class="btn btn-secondary btn-sm">Ledger</a>
                            @allowed('accounting', 'manage')
                                <a href="{{ route('accounting.accounts.edit', $a) }}" class="btn btn-secondary btn-sm">Edit</a>
                                @if (! $a->is_system)
                                    <form method="POST" action="{{ route('accounting.accounts.toggle', $a) }}" class="inline" onsubmit="return confirm('Change this account\'s status?')">
                                        @csrf <button class="btn btn-sm {{ $a->is_active ? 'btn-danger' : 'btn-success' }}">{{ $a->is_active ? 'Deactivate' : 'Activate' }}</button>
                                    </form>
                                    @if ($a->lines_count === 0)
                                        <form method="POST" action="{{ route('accounting.accounts.destroy', $a) }}" class="inline" onsubmit="return confirm('Delete this account?')">
                                            @csrf @method('DELETE') <button class="btn btn-danger btn-sm">Delete</button>
                                        </form>
                                    @endif
                                @endif
                            @endallowed
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="p-space-md text-[11px] text-outline">SYSTEM accounts are posted to automatically (cash, savings, share capital, loans, income). They can be renamed but not deleted or deactivated. An account with entries cannot be deleted, only deactivated.</p>
</x-card>
@endsection
