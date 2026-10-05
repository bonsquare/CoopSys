@extends('layouts.app')

@section('title', 'Accounting')

@section('content')
<x-page-header title="Accounting" subtitle="Journal, ledger and financial statements. Entries from transactions are posted automatically.">
    @allowed('accounting', 'manage')
        <a href="{{ route('accounting.journal.create') }}" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">edit_note</span> New Journal Entry</a>
    @endallowed
</x-page-header>

@include('accounting._nav')

@php $allOk = collect($checks)->every(fn ($c) => $c['ok']); @endphp

<div class="{{ $checks[0]['ok'] ? 'bg-secondary-fixed text-on-secondary-fixed' : 'bg-error-container text-on-error-container' }} px-space-md py-space-sm rounded-lg font-label-md text-label-md font-semibold">
    {{ $checks[0]['ok'] ? '✔ The books are balanced (debits equal credits).' : '✖ The books are out of balance!' }}
</div>

@if ($lockedUntil)
    <div class="px-space-md py-space-sm rounded-lg bg-surface-container-high text-primary font-label-md text-label-md">
        <span class="material-symbols-outlined text-[16px] align-middle">lock</span> The books are closed up to {{ $lockedUntil->format('M d, Y') }}. <a href="{{ route('accounting.closing') }}" class="underline font-semibold">Year-end closing</a>
    </div>
@endif

<div class="grid grid-cols-2 lg:grid-cols-5 gap-gutter">
    <x-card class="p-space-md"><div class="field-label">Total assets</div><div class="font-headline-md text-headline-md font-bold">@acct($bs['total_assets'])</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Total liabilities</div><div class="font-headline-md text-headline-md font-bold">@acct($bs['total_liabilities'])</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Total equity</div><div class="font-headline-md text-headline-md font-bold">@acct($bs['total_equity'])</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Income {{ $year }}</div><div class="font-headline-md text-headline-md font-bold text-secondary">@acct($is['total_income'])</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Net surplus {{ $year }}</div><div class="font-headline-md text-headline-md font-bold {{ $is['net'] >= 0 ? 'text-secondary' : 'text-error' }}">@acct($is['net'])</div></x-card>
</div>

<x-card title="Reconciliation Checks" subtitle="Does the ledger agree with the member and loan records? Every line should show a tick.">
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Check</th><th class="num">Ledger</th><th class="num">Records</th><th class="num">Difference</th><th>Result</th></tr></thead>
            <tbody>
                @foreach ($checks as $c)
                    <tr>
                        <td>{{ $c['label'] }}</td>
                        <td class="num">@acct($c['ledger'])</td>
                        <td class="num">@acct($c['records'])</td>
                        <td class="num {{ $c['ok'] ? '' : 'text-error font-bold' }}">@acct($c['ledger'] - $c['records'])</td>
                        <td>{!! $c['ok'] ? '<span class="text-secondary font-semibold">✔ Agrees</span>' : '<span class="text-error font-semibold">✖ Check</span>' !!}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="p-space-md text-[11px] text-outline">The books are on a cash basis: interest and fees are income when collected or deducted at release, not when they fall due.</p>
</x-card>

<x-card title="Reports" subtitle="Statements and management reports">
    <div class="p-space-md pt-0 flex flex-wrap gap-2">
        <a href="{{ route('accounting.income') }}" class="btn btn-secondary btn-sm">Income Statement</a>
        <a href="{{ route('accounting.balance') }}" class="btn btn-secondary btn-sm">Balance Sheet</a>
        <a href="{{ route('accounting.cashflow') }}" class="btn btn-secondary btn-sm">Cash Flow</a>
        <a href="{{ route('accounting.highlights') }}" class="btn btn-secondary btn-sm">Financial Highlights</a>
        <a href="{{ route('accounting.aging') }}" class="btn btn-secondary btn-sm">Loan Aging</a>
        <a href="{{ route('accounting.schedules') }}" class="btn btn-secondary btn-sm">Subsidiary Schedules</a>
        <a href="{{ route('accounting.reports') }}" class="btn btn-primary btn-sm">All reports</a>
    </div>
</x-card>

<x-card title="Latest Journal Entries">
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Date</th><th>Entry</th><th>Description</th><th>Source</th><th class="num">Amount</th></tr></thead>
            <tbody>
                @foreach ($recent as $e)
                    <tr>
                        <td class="whitespace-nowrap">{{ $e->entry_date->format('M d, Y') }}</td>
                        <td class="font-mono text-[12px] font-semibold text-primary"><a href="{{ route('accounting.journal.show', $e) }}">{{ $e->number() }}</a></td>
                        <td>{{ $e->description }}</td>
                        <td>{{ $e->isManual() ? 'Manual' : 'Automatic' }}</td>
                        <td class="num">@peso($e->lines_sum_debit)</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="p-space-md"><a href="{{ route('accounting.journal.index') }}" class="text-primary font-semibold text-sm">View the whole journal →</a></div>
</x-card>
@endsection
