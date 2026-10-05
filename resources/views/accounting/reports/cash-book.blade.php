@extends('layouts.app')

@section('title', 'Cash Book')

@php
    $fmt = fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('M d, Y');
    $period = $fmt($from) . ' to ' . $fmt($to);
@endphp

@section('content')
<x-page-header title="Cash Book" :subtitle="'Cash on hand and in bank, ' . $period">
    <button type="button" onclick="window.print()" class="btn btn-secondary"><span class="material-symbols-outlined text-[18px]">print</span> Print</button>
    <a href="{{ route('accounting.cashbook', ['from' => $from, 'to' => $to, 'export' => 'csv']) }}" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">download</span> Export Excel/CSV</a>
</x-page-header>

@include('accounting._nav')
@include('accounting._print-header', ['title' => 'Cash Book', 'subtitle' => $period])

<div class="grid grid-cols-2 lg:grid-cols-4 gap-gutter">
    <x-card class="p-space-md"><div class="field-label">Opening balance</div><div class="font-headline-md text-headline-md font-bold">@acct($book['opening'])</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Receipts</div><div class="font-headline-md text-headline-md font-bold text-secondary">@peso($book['receipts'])</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Payments</div><div class="font-headline-md text-headline-md font-bold">@peso($book['payments'])</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Closing balance</div><div class="font-headline-md text-headline-md font-bold text-primary">@acct($book['closing'])</div></x-card>
</div>

<x-card>
    <form method="GET" class="p-space-md flex flex-wrap items-end gap-3 no-print">
        <x-field label="From" name="from" type="date" :value="$from" />
        <x-field label="To" name="to" type="date" :value="$to" />
        <button class="btn btn-primary">Apply</button>
    </form>
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Date</th><th>Entry</th><th>Particulars</th><th>Account</th><th class="num">Receipts</th><th class="num">Payments</th><th class="num">Balance</th></tr></thead>
            <tbody>
                <tr class="bg-surface-container-low font-semibold"><td colspan="6">Opening balance</td><td class="num">@acct($book['opening'])</td></tr>
                @forelse ($book['rows'] as $r)
                    @php $l = $r['line']; @endphp
                    <tr>
                        <td class="whitespace-nowrap">{{ $l->entry->entry_date->format('M d, Y') }}</td>
                        <td class="font-mono text-[12px] font-semibold text-primary"><a href="{{ route('accounting.journal.show', $l->entry) }}">{{ $l->entry->number() }}</a></td>
                        <td>{{ $l->entry->description }}</td>
                        <td>{{ $l->account->name }}</td>
                        <td class="num text-secondary">{{ $l->debit > 0 ? '₱' . number_format($l->debit, 2) : '' }}</td>
                        <td class="num">{{ $l->credit > 0 ? '₱' . number_format($l->credit, 2) : '' }}</td>
                        <td class="num">@acct($r['balance'])</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-on-surface-variant py-6">No cash movements in this period.</td></tr>
                @endforelse
                <tr class="font-bold bg-surface-container-low border-t-2 border-outline-variant">
                    <td colspan="4" class="text-right">Totals / closing balance</td>
                    <td class="num">@peso($book['receipts'])</td><td class="num">@peso($book['payments'])</td><td class="num">@acct($book['closing'])</td>
                </tr>
            </tbody>
        </table>
    </div>
</x-card>
@endsection
