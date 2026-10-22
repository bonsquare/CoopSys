@extends('layouts.app')

@section('title', 'Receipts')

@section('content')
<x-page-header title="Collection Receipts" subtitle="Every receipt, whether it is a draft, posted or cancelled.">
    @allowed('collections', 'manage')
        <a href="{{ route('collections.create') }}" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">point_of_sale</span> New Transaction</a>
    @endallowed
</x-page-header>

<x-card>
    <form method="GET" class="p-space-md grid grid-cols-2 md:grid-cols-6 gap-3 items-end no-print">
        <div class="col-span-2">
            <label class="field-label" for="q">Search</label>
            <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="input" placeholder="Receipt no. or member">
        </div>
        <x-select label="Status" name="status" :value="$filters['status'] ?? null" :options="config('collections.statuses')" placeholder="All" />
        <x-select label="Type" name="type" :value="$filters['type'] ?? null" :options="collect(config('collections.types'))->map(fn ($t) => $t['label'])->all()" placeholder="All" />
        <x-field label="From" name="from" type="date" :value="$filters['from'] ?? null" />
        <x-field label="To" name="to" type="date" :value="$filters['to'] ?? null" />
        <x-select label="Collected by" name="by" :value="$filters['by'] ?? null" :options="$users" placeholder="Anyone" class="col-span-2" />
        <div class="col-span-2 flex gap-2">
            <button class="btn btn-secondary">Filter</button>
            @if (array_filter($filters)) <a href="{{ route('collections.index') }}" class="btn btn-secondary">Clear</a> @endif
        </div>
    </form>

    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Receipt no.</th><th>Date</th><th>Member</th><th>Type</th><th>Method</th><th class="num">Amount</th><th>Collected by</th><th>Status</th><th class="no-print"></th></tr></thead>
            <tbody>
                @forelse ($collections as $c)
                    <tr>
                        <td class="font-mono text-[12px] font-semibold text-primary"><a href="{{ route('collections.show', $c) }}">{{ $c->receipt_no }}</a></td>
                        <td>{{ $c->collection_date->format('M d, Y') }}</td>
                        <td>{{ $c->member->full_name }}<div class="text-xs text-outline">{{ $c->member->member_no }}</div></td>
                        <td>{{ $c->typeLabel() }}</td>
                        <td>{{ config('coop.payment_methods.' . $c->payment_method, $c->payment_method) }}</td>
                        <td class="num font-bold {{ $c->direction === 'in' ? 'text-secondary' : '' }} {{ $c->status === 'cancelled' ? 'line-through opacity-60' : '' }}">{{ $c->direction === 'in' ? '+' : '-' }}@peso($c->total_amount)</td>
                        <td>{{ $c->collector?->name }}</td>
                        <td><span class="inline-flex px-2 py-0.5 rounded text-xs font-semibold {{ $c->statusColor() }}">{{ $c->statusLabel() }}</span></td>
                        <td class="no-print whitespace-nowrap">
                            <a href="{{ route('collections.show', $c) }}" class="btn btn-secondary btn-sm">Open</a>
                            @if (! $c->isDraft()) <a href="{{ route('collections.receipt', $c) }}" class="btn btn-secondary btn-sm">Receipt</a> @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-on-surface-variant py-8">No receipts found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-space-md no-print">{{ $collections->links() }}</div>
</x-card>
@endsection
