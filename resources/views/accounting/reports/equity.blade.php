@extends('layouts.app')

@section('title', 'Changes in Equity')

@php
    $fmt = fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('M d, Y');
    $period = $fmt($from) . ' to ' . $fmt($to);
    $s = $eq['surplus'];
@endphp

@section('content')
<x-page-header title="Statement of Changes in Equity" :subtitle="'For the period ' . $period">
    <button type="button" onclick="window.print()" class="btn btn-secondary"><span class="material-symbols-outlined text-[18px]">print</span> Print</button>
    <a href="{{ route('accounting.equity', ['from' => $from, 'to' => $to, 'export' => 'csv']) }}" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">download</span> Export Excel/CSV</a>
</x-page-header>

@include('accounting._nav')
@include('accounting._print-header', ['title' => 'Statement of Changes in Equity', 'subtitle' => $period])

<x-card>
    <form method="GET" class="p-space-md flex flex-wrap items-end gap-3 no-print">
        <x-field label="From" name="from" type="date" :value="$from" />
        <x-field label="To" name="to" type="date" :value="$to" />
        <button class="btn btn-primary">Apply</button>
        <a href="{{ route('accounting.equity', ['from' => now()->startOfYear()->toDateString(), 'to' => now()->toDateString()]) }}" class="btn btn-secondary">Year to date</a>
    </form>

    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Component of equity</th><th class="num">Opening balance</th><th class="num">Additions</th><th class="num">Reductions</th><th class="num">Closing balance</th></tr></thead>
            <tbody>
                @foreach ($eq['rows'] as $r)
                    <tr>
                        <td>{{ $r['label'] }}</td>
                        <td class="num">@acct($r['opening'])</td>
                        <td class="num text-secondary">{{ $r['added'] > 0 ? '+' . number_format($r['added'], 2) : '' }}</td>
                        <td class="num text-error">{{ $r['reduced'] > 0 ? '(' . number_format($r['reduced'], 2) . ')' : '' }}</td>
                        <td class="num font-semibold">@acct($r['closing'])</td>
                    </tr>
                @endforeach
                <tr>
                    <td>{{ $s['label'] }}
                        <div class="text-[11px] text-outline">Net surplus for the period, less any amount closed into retained surplus at year-end.</div></td>
                    <td class="num">@acct($s['opening'])</td>
                    <td class="num {{ $s['net'] >= 0 ? 'text-secondary' : 'text-error' }}">@acct($s['net'])</td>
                    <td class="num text-error">{{ abs($s['closed']) >= 0.005 ? '(' . number_format(abs($s['closed']), 2) . ')' : '' }}</td>
                    <td class="num font-semibold">@acct($s['closing'])</td>
                </tr>
                <tr class="bg-primary text-on-primary font-bold text-sm">
                    <td>TOTAL EQUITY</td><td class="num">@acct($eq['opening_total'])</td><td></td><td></td><td class="num">@acct($eq['closing_total'])</td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="p-space-md text-sm font-semibold {{ $eq['agrees'] ? 'text-secondary' : 'text-error' }}">
        {{ $eq['agrees'] ? '✔ The closing equity agrees with the balance sheet.' : '✖ The closing equity does not agree with the balance sheet.' }}
    </div>
</x-card>
@endsection
