@extends('layouts.app')

@section('title', 'General Ledger')

@section('content')
<x-page-header title="General Ledger" :subtitle="$account->label() . ' · ' . \App\Models\Account::TYPES[$account->type] . ' · normal balance ' . $account->normalSide()">
    <button type="button" onclick="window.print()" class="btn btn-secondary"><span class="material-symbols-outlined text-[18px]">print</span> Print</button>
    <a href="{{ route('accounting.ledger', ['account' => $account->id, 'from' => $from, 'to' => $to, 'export' => 'csv']) }}" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">download</span> Export Excel/CSV</a>
</x-page-header>

@include('accounting._nav')

<x-card>
    <form method="GET" class="p-space-md grid grid-cols-2 md:grid-cols-5 gap-3 items-end no-print">
        <x-select label="Account" name="account" :value="$account->id" :options="$accounts" class="col-span-2" />
        <x-field label="From" name="from" type="date" :value="$from" />
        <x-field label="To" name="to" type="date" :value="$to" />
        <button class="btn btn-secondary justify-center">Show</button>
    </form>

    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Date</th><th>Entry</th><th>Description</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead>
            <tbody>
                <tr class="bg-surface-container-low font-semibold"><td colspan="5">Opening balance</td><td class="num">@acct($ledger['opening'])</td></tr>
                @forelse ($ledger['rows'] as $r)
                    @php $l = $r['line']; @endphp
                    <tr>
                        <td class="whitespace-nowrap">{{ $l->entry->entry_date->format('M d, Y') }}</td>
                        <td class="font-mono text-[12px] font-semibold text-primary"><a href="{{ route('accounting.journal.show', $l->entry) }}">{{ $l->entry->number() }}</a></td>
                        <td>{{ $l->entry->description }}@if ($l->memo)<div class="text-[11px] text-outline">{{ $l->memo }}</div>@endif</td>
                        <td class="num">{{ $l->debit > 0 ? '₱' . number_format($l->debit, 2) : '' }}</td>
                        <td class="num">{{ $l->credit > 0 ? '₱' . number_format($l->credit, 2) : '' }}</td>
                        <td class="num">@acct($r['balance'])</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-on-surface-variant py-6">No entries in this period.</td></tr>
                @endforelse
                <tr class="bg-surface-container-low font-bold">
                    <td colspan="3" class="text-right">Totals / closing balance</td>
                    <td class="num">@peso($ledger['debits'])</td><td class="num">@peso($ledger['credits'])</td><td class="num">@acct($ledger['closing'])</td>
                </tr>
            </tbody>
        </table>
    </div>
</x-card>
@endsection
