@extends('layouts.app')

@section('title', 'Transaction Ledger')

@section('content')
<x-page-header title="Transaction Ledger" subtitle="Every posted transaction. Filter, print or export.">
    <button type="button" onclick="window.print()" class="btn btn-secondary"><span class="material-symbols-outlined text-[18px]">print</span> Print</button>
    <a href="{{ route('transactions.export', $filters) }}" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">download</span> Export Excel/CSV</a>
</x-page-header>

<div class="grid grid-cols-1 md:grid-cols-3 gap-gutter">
    <x-card class="p-space-md"><div class="field-label">Cash In (filtered)</div><div class="font-headline-md text-headline-md font-bold text-secondary">@peso($totals['in'])</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Cash Out (filtered)</div><div class="font-headline-md text-headline-md font-bold">@peso($totals['out'])</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Net</div><div class="font-headline-md text-headline-md font-bold">@peso($totals['in'] - $totals['out'])</div></x-card>
</div>

<x-card>
    <form method="GET" class="p-space-md grid grid-cols-2 md:grid-cols-6 gap-3 items-end no-print">
        <div class="col-span-2">
            <label class="field-label" for="q">Search</label>
            <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Reference, member, remarks" class="input">
        </div>
        <x-select label="Type" name="type" :value="$filters['type'] ?? null" :options="$types" placeholder="All" />
        <x-select label="Status" name="status" :value="$filters['status'] ?? null" :options="['completed' => 'Completed', 'voided' => 'Voided']" placeholder="All" />
        <x-field label="From" name="from" type="date" :value="$filters['from'] ?? null" />
        <x-field label="To" name="to" type="date" :value="$filters['to'] ?? null" />
        <div class="col-span-2 md:col-span-6 flex gap-2">
            <button class="btn btn-secondary">Apply filters</button>
            @if (array_filter($filters)) <a href="{{ route('transactions.index') }}" class="btn btn-secondary">Clear</a> @endif
        </div>
    </form>

    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Reference</th><th>Date &amp; Time</th><th>Member</th><th>Type</th><th class="num">Amount</th><th>By</th><th>Status</th><th class="no-print"></th></tr></thead>
            <tbody>
                @forelse ($transactions as $t)
                    <tr>
                        <td class="font-mono text-[12px] font-semibold text-primary">{{ $t->reference_no }}</td>
                        <td>{{ $t->posted_at->format('M d, Y h:i A') }}</td>
                        <td>{{ $t->member?->full_name ?? '—' }}<div class="text-xs text-outline">{{ $t->member?->member_no }}</div></td>
                        <td><span class="inline-flex px-2 py-0.5 rounded text-xs font-semibold {{ $t->badge() }}">{{ $t->label() }}</span></td>
                        <td class="num font-bold {{ $t->direction === 'in' ? 'text-secondary' : '' }} {{ $t->status === 'voided' ? 'line-through opacity-60' : '' }}">{{ $t->direction === 'in' ? '+' : '-' }}@peso($t->amount)</td>
                        <td>{{ $t->user?->name ?? 'System' }}</td>
                        <td><span class="{{ $t->status === 'completed' ? 'text-secondary' : 'text-error' }} font-semibold">{{ ucfirst($t->status) }}</span></td>
                        <td class="no-print"><a href="{{ route('transactions.show', $t) }}" class="btn btn-secondary btn-sm">View</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-on-surface-variant py-8">No transactions match.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-space-md no-print">{{ $transactions->links() }}</div>
</x-card>
@endsection
