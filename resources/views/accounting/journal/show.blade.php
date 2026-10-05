@extends('layouts.app')

@section('title', 'Entry ' . $entry->number())

@section('content')
<x-page-header :title="'Journal Entry ' . $entry->number()" :subtitle="$entry->entry_date->format('F d, Y') . ' · ' . ($entry->isManual() ? 'Manual entry' : 'Automatic entry')">
    <a href="{{ route('accounting.journal.index') }}" class="btn btn-secondary">Back to journal</a>
    <button type="button" onclick="window.print()" class="btn btn-secondary"><span class="material-symbols-outlined text-[18px]">print</span> Print</button>
</x-page-header>

@include('accounting._nav')

<div class="grid grid-cols-1 lg:grid-cols-3 gap-gutter items-start">
    <x-card title="Details" class="lg:col-span-1">
        <dl class="p-space-md pt-0 grid grid-cols-3 gap-y-2 text-sm">
            <dt class="text-on-surface-variant">Entry no.</dt><dd class="col-span-2 font-mono font-semibold">{{ $entry->number() }}</dd>
            <dt class="text-on-surface-variant">Date</dt><dd class="col-span-2">{{ $entry->entry_date->format('M d, Y') }}</dd>
            <dt class="text-on-surface-variant">Description</dt><dd class="col-span-2">{{ $entry->description }}</dd>
            <dt class="text-on-surface-variant">Reference</dt><dd class="col-span-2">{{ $entry->reference ?: '—' }}</dd>
            <dt class="text-on-surface-variant">Posted by</dt><dd class="col-span-2">{{ $entry->user?->name ?? 'System' }}</dd>
            <dt class="text-on-surface-variant">Status</dt>
            <dd class="col-span-2 font-semibold {{ $entry->status === 'reversed' ? 'text-error' : 'text-secondary' }}">{{ $entry->status === 'reversed' ? 'Reversed' : ($entry->reversal_of ? 'Reversal entry' : 'Posted') }}</dd>
            @if ($entry->transaction)
                <dt class="text-on-surface-variant">Transaction</dt>
                <dd class="col-span-2">
                    <a class="text-primary" href="{{ route('transactions.show', $entry->transaction) }}">{{ $entry->transaction->reference_no }}</a>
                    @if ($entry->transaction->collection) · <a class="text-primary" href="{{ route('collections.show', $entry->transaction->collection) }}">receipt {{ $entry->transaction->collection->receipt_no }}</a> @endif
                </dd>
            @endif
            @if ($entry->original)
                <dt class="text-on-surface-variant">Reverses</dt><dd class="col-span-2"><a class="text-primary" href="{{ route('accounting.journal.show', $entry->original) }}">{{ $entry->original->number() }}</a></dd>
            @endif
            @if ($entry->reversal)
                <dt class="text-on-surface-variant">Reversed by</dt><dd class="col-span-2"><a class="text-primary" href="{{ route('accounting.journal.show', $entry->reversal) }}">{{ $entry->reversal->number() }}</a></dd>
            @endif
        </dl>

        @if ($entry->isManual() && $entry->status === 'posted' && ! $entry->reversal_of)
            @allowed('accounting', 'manage')
                <form method="POST" action="{{ route('accounting.journal.reverse', $entry) }}" class="p-space-md pt-0 flex flex-col gap-2 no-print"
                      onsubmit="return confirm('Reverse this entry? A new entry that swaps every debit and credit will be posted.')">
                    @csrf
                    <label class="field-label" for="reason">Reverse this entry</label>
                    <input id="reason" name="reason" required maxlength="150" class="input" placeholder="Reason">
                    <button class="btn btn-danger">Reverse entry</button>
                </form>
            @endallowed
        @elseif (! $entry->isManual())
            <p class="p-space-md pt-0 text-[11px] text-outline">This entry was posted from a transaction. To undo it, cancel the transaction or receipt.</p>
        @endif
    </x-card>

    <x-card title="Lines" class="lg:col-span-2">
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr><th>Account</th><th>Memo</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
                <tbody>
                    @foreach ($entry->lines as $l)
                        <tr>
                            <td class="{{ $l->credit > 0 ? 'pl-8' : '' }}"><a href="{{ route('accounting.ledger', ['account' => $l->account_id]) }}" class="hover:underline">{{ $l->account->label() }}</a></td>
                            <td class="text-on-surface-variant">{{ $l->memo }}</td>
                            <td class="num">{{ $l->debit > 0 ? '₱' . number_format($l->debit, 2) : '' }}</td>
                            <td class="num">{{ $l->credit > 0 ? '₱' . number_format($l->credit, 2) : '' }}</td>
                        </tr>
                    @endforeach
                    <tr class="font-bold bg-surface-container-low">
                        <td colspan="2" class="text-right">Totals</td>
                        <td class="num">@peso($entry->lines->sum('debit'))</td><td class="num">@peso($entry->lines->sum('credit'))</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </x-card>
</div>
@endsection
