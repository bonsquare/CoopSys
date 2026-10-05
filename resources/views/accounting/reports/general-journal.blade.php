@extends('layouts.app')

@section('title', 'General Journal')

@php
    $fmt = fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('M d, Y');
    $period = $fmt($from) . ' to ' . $fmt($to);
@endphp

@section('content')
<x-page-header title="General Journal" :subtitle="'For the period ' . $period">
    <button type="button" onclick="window.print()" class="btn btn-secondary"><span class="material-symbols-outlined text-[18px]">print</span> Print</button>
    <a href="{{ route('accounting.daybook', ['from' => $from, 'to' => $to, 'source' => $source, 'export' => 'csv']) }}" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">download</span> Export Excel/CSV</a>
</x-page-header>

@include('accounting._nav')
@include('accounting._print-header', ['title' => 'General Journal', 'subtitle' => $period])

<x-card>
    <form method="GET" class="p-space-md flex flex-wrap items-end gap-3 no-print">
        <x-field label="From" name="from" type="date" :value="$from" />
        <x-field label="To" name="to" type="date" :value="$to" />
        <x-select label="Source" name="source" :value="$source" :options="['manual' => 'Manual entries', 'system' => 'Automatic (transactions)', 'closing' => 'Year-end closing']" placeholder="All" />
        <button class="btn btn-primary">Apply</button>
    </form>

    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Date</th><th>Entry</th><th>Account / description</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
            <tbody>
                @forelse ($journal['entries'] as $e)
                    <tr class="bg-surface-container-low">
                        <td class="whitespace-nowrap font-semibold">{{ $e->entry_date->format('M d, Y') }}</td>
                        <td class="font-mono text-[12px] font-semibold text-primary"><a href="{{ route('accounting.journal.show', $e) }}">{{ $e->number() }}</a></td>
                        <td colspan="3" class="font-semibold">{{ $e->description }}</td>
                    </tr>
                    @foreach ($e->lines as $l)
                        <tr>
                            <td></td><td></td>
                            <td class="{{ $l->credit > 0 ? 'pl-10' : 'pl-4' }}">{{ $l->account->label() }}@if ($l->memo)<span class="text-[11px] text-outline ml-2">{{ $l->memo }}</span>@endif</td>
                            <td class="num">{{ $l->debit > 0 ? '₱' . number_format($l->debit, 2) : '' }}</td>
                            <td class="num">{{ $l->credit > 0 ? '₱' . number_format($l->credit, 2) : '' }}</td>
                        </tr>
                    @endforeach
                @empty
                    <tr><td colspan="5" class="text-center text-on-surface-variant py-8">No entries in this period.</td></tr>
                @endforelse
                <tr class="font-bold border-t-2 border-outline-variant"><td colspan="3" class="text-right">Totals</td><td class="num">@peso($journal['debits'])</td><td class="num">@peso($journal['credits'])</td></tr>
            </tbody>
        </table>
    </div>
</x-card>
@endsection
