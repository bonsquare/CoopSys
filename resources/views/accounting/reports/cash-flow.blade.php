@extends('layouts.app')

@section('title', 'Cash Flow')

@php
    $fmt = fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('M d, Y');
    $period = $fmt($from) . ' to ' . $fmt($to);
    $titles = ['operating' => 'Operating activities', 'investing' => 'Investing activities', 'financing' => 'Financing activities'];
    $notes = [
        'operating' => 'Interest, fees and penalties collected, expenses paid, and other receipts and payments.',
        'investing' => 'Loans released to members, and loan principal collected.',
        'financing' => 'Member deposits and share capital received or paid back.',
    ];
@endphp

@section('content')
<x-page-header title="Cash Flow Statement" :subtitle="'For the period ' . $period">
    <button type="button" onclick="window.print()" class="btn btn-secondary"><span class="material-symbols-outlined text-[18px]">print</span> Print</button>
    <a href="{{ route('accounting.cashflow', ['from' => $from, 'to' => $to, 'export' => 'csv']) }}" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">download</span> Export Excel/CSV</a>
</x-page-header>

@include('accounting._nav')
@include('accounting._print-header', ['title' => 'Cash Flow Statement', 'subtitle' => $period])

<x-card>
    <form method="GET" class="p-space-md flex flex-wrap items-end gap-3 no-print">
        <x-field label="From" name="from" type="date" :value="$from" />
        <x-field label="To" name="to" type="date" :value="$to" />
        <button class="btn btn-primary">Apply</button>
        <a href="{{ route('accounting.cashflow', ['from' => now()->startOfYear()->toDateString(), 'to' => now()->toDateString()]) }}" class="btn btn-secondary">Year to date</a>
    </form>

    <div class="overflow-x-auto">
        <table class="data-table">
            <tbody>
                @foreach ($titles as $key => $title)
                    <tr class="bg-surface-container-low font-bold"><td colspan="2">{{ strtoupper($title) }}<div class="text-xs font-normal text-outline normal-case tracking-normal">{{ $notes[$key] }}</div></td></tr>
                    @forelse ($cf['sections'][$key] as $l)
                        <tr>
                            <td class="pl-6"><span class="font-mono text-xs text-outline mr-2">{{ $l['account']->code }}</span>{{ $l['account']->name }}</td>
                            <td class="num {{ $l['amount'] < 0 ? 'text-error' : '' }}">@acct($l['amount'])</td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="text-on-surface-variant pl-6">No cash movements.</td></tr>
                    @endforelse
                    <tr class="font-bold border-t-2 border-outline-variant"><td class="text-right">Net cash from {{ strtolower($title) }}</td><td class="num">@acct($cf['totals'][$key])</td></tr>
                @endforeach

                <tr class="font-bold bg-surface-container-low"><td class="text-right">NET CHANGE IN CASH AND BANK</td><td class="num">@acct($cf['net'])</td></tr>
                <tr><td class="text-right">Cash and bank at the start of the period</td><td class="num">@acct($cf['opening'])</td></tr>
                <tr class="bg-primary text-on-primary font-bold text-sm"><td class="text-right">CASH AND BANK AT THE END OF THE PERIOD</td><td class="num">@acct($cf['closing'])</td></tr>
            </tbody>
        </table>
    </div>
    <div class="p-space-md text-sm font-semibold {{ $cf['agrees'] ? 'text-secondary' : 'text-error' }}">
        {{ $cf['agrees'] ? '✔ The closing cash agrees with the cash and bank accounts on the balance sheet.' : '✖ The cash flow does not agree with the cash accounts.' }}
    </div>
    <p class="px-space-md pb-space-md text-xs text-outline">Direct method: every cash entry is placed in a section by the account on its other side. Accounts in the "Cash and Bank" report group are treated as cash.</p>
</x-card>
@endsection
