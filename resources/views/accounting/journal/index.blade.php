@extends('layouts.app')

@section('title', 'Journal')

@section('content')
<x-page-header title="Journal" subtitle="Every entry in the books: automatic ones from transactions, and manual ones.">
    @allowed('accounting', 'manage')
        <a href="{{ route('accounting.journal.create') }}" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">edit_note</span> New Journal Entry</a>
    @endallowed
</x-page-header>

@include('accounting._nav')

<x-card>
    <form method="GET" class="p-space-md grid grid-cols-2 md:grid-cols-6 gap-3 items-end no-print">
        <div class="col-span-2">
            <label class="field-label" for="q">Search</label>
            <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="input" placeholder="Entry no., reference or description">
        </div>
        <x-select label="Source" name="source" :value="$filters['source'] ?? null" :options="['manual' => 'Manual entries', 'system' => 'Automatic (transactions)']" placeholder="All" />
        <x-field label="From" name="from" type="date" :value="$filters['from'] ?? null" />
        <x-field label="To" name="to" type="date" :value="$filters['to'] ?? null" />
        <div class="flex gap-2">
            <button class="btn btn-secondary">Filter</button>
            @if (array_filter($filters)) <a href="{{ route('accounting.journal.index') }}" class="btn btn-secondary">Clear</a> @endif
        </div>
    </form>

    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Date</th><th>Entry</th><th>Description</th><th>Source</th><th class="num">Amount</th><th>Posted by</th><th>Status</th></tr></thead>
            <tbody>
                @forelse ($entries as $e)
                    <tr class="{{ $e->status === 'reversed' ? 'opacity-60' : '' }}">
                        <td class="whitespace-nowrap">{{ $e->entry_date->format('M d, Y') }}</td>
                        <td class="font-mono text-[12px] font-semibold text-primary"><a href="{{ route('accounting.journal.show', $e) }}">{{ $e->number() }}</a></td>
                        <td>{{ $e->description }}</td>
                        <td>{{ $e->isManual() ? 'Manual' : 'Automatic' }}</td>
                        <td class="num font-semibold">@peso($e->lines_sum_debit)</td>
                        <td>{{ $e->user?->name ?? 'System' }}</td>
                        <td>{{ $e->status === 'reversed' ? 'Reversed' : ($e->reversal_of ? 'Reversal' : 'Posted') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-on-surface-variant py-8">No journal entries found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-space-md no-print">{{ $entries->links() }}</div>
</x-card>
@endsection
