@extends('layouts.app')

@section('title', 'Loan Aging')

@section('content')
<x-page-header title="Loan Portfolio Aging" :subtitle="'As of ' . \Illuminate\Support\Carbon::parse($aging['as_of'])->format('F d, Y')">
    <button type="button" onclick="window.print()" class="btn btn-secondary"><span class="material-symbols-outlined text-[18px]">print</span> Print</button>
    <a href="{{ route('accounting.aging', ['export' => 'csv']) }}" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">download</span> Export Excel/CSV</a>
</x-page-header>

@include('accounting._nav')
@include('accounting._print-header', ['title' => 'Loan Portfolio Aging', 'subtitle' => 'As of ' . \Illuminate\Support\Carbon::parse($aging['as_of'])->format('M d, Y')])

<div class="grid grid-cols-2 lg:grid-cols-5 gap-gutter">
    <x-card class="p-space-md"><div class="field-label">Loan book</div><div class="font-headline-md text-headline-md font-bold">@peso($aging['total_balance'])</div><div class="text-[11px] text-outline">{{ $aging['total_count'] }} active loans</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Overdue amount</div><div class="font-headline-md text-headline-md font-bold {{ $aging['total_overdue'] > 0 ? 'text-error' : '' }}">@peso($aging['total_overdue'])</div><div class="text-[11px] text-outline">Installments missed</div></x-card>
    <x-card class="p-space-md"><div class="field-label">PAR over 0 days</div><div class="font-headline-md text-headline-md font-bold">{{ $aging['par'][0] }}%</div></x-card>
    <x-card class="p-space-md"><div class="field-label">PAR over 30 days</div><div class="font-headline-md text-headline-md font-bold">{{ $aging['par'][30] }}%</div></x-card>
    <x-card class="p-space-md"><div class="field-label">PAR over 90 days</div><div class="font-headline-md text-headline-md font-bold">{{ $aging['par'][90] }}%</div></x-card>
</div>

<x-card title="Aging Summary" subtitle="The amount is the balance carried in the books: principal and fees still to collect.">
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Days past due</th><th class="num">Loans</th><th class="num">Book balance</th><th class="num">% of book</th><th class="num">Overdue amount</th><th class="num">Allowance rate</th><th class="num">Suggested allowance</th></tr></thead>
            <tbody>
                @foreach ($aging['buckets'] as $b)
                    <tr>
                        <td class="font-medium">{{ $b['label'] }}</td>
                        <td class="num">{{ $b['count'] }}</td>
                        <td class="num">@peso($b['balance'])</td>
                        <td class="num text-on-surface-variant">{{ $aging['total_balance'] > 0 ? round($b['balance'] / $aging['total_balance'] * 100, 1) . '%' : '—' }}</td>
                        <td class="num {{ $b['overdue'] > 0 ? 'text-error' : '' }}">@peso($b['overdue'])</td>
                        <td class="num text-on-surface-variant">{{ $b['rate'] }}%</td>
                        <td class="num">@peso($b['allowance'])</td>
                    </tr>
                @endforeach
                <tr class="font-bold bg-surface-container-low">
                    <td>Total</td><td class="num">{{ $aging['total_count'] }}</td><td class="num">@peso($aging['total_balance'])</td><td></td>
                    <td class="num">@peso($aging['total_overdue'])</td><td></td><td class="num">@peso($aging['total_allowance'])</td>
                </tr>
            </tbody>
        </table>
    </div>
    <p class="p-space-md text-[11px] text-outline">PAR (portfolio at risk) is the share of the loan book that is more than that many days past due. The allowance is a suggestion using the rates in config/accounting.php. It is not booked automatically: record it with a journal entry if you decide to set it aside.</p>
</x-card>

<x-card title="Loans by Days Past Due" subtitle="Most overdue first">
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Loan</th><th>Member</th><th>Product</th><th class="num">Days past due</th><th>Bucket</th><th class="num">Book balance</th><th class="num">Overdue amount</th><th class="num">Penalty due</th><th class="no-print"></th></tr></thead>
            <tbody>
                @forelse ($aging['loans'] as $d)
                    <tr class="{{ $d['days'] > 0 ? 'bg-error-container/30' : '' }}">
                        <td class="font-mono text-[12px] font-semibold text-primary">{{ $d['loan']->loan_no }}</td>
                        <td>{{ $d['loan']->member->full_name }}</td>
                        <td>{{ $d['loan']->product?->name ?? '—' }}</td>
                        <td class="num font-semibold {{ $d['days'] > 0 ? 'text-error' : '' }}">{{ $d['days'] }}</td>
                        <td>{{ $d['bucket'] }}</td>
                        <td class="num">@peso($d['book'])</td>
                        <td class="num">@peso($d['overdue'])</td>
                        <td class="num">@peso($d['penalty'])</td>
                        <td class="no-print"><a href="{{ route('loans.ledger', $d['loan']) }}" class="btn btn-secondary btn-sm">Ledger</a></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-on-surface-variant py-8">There are no active loans.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-card>
@endsection
