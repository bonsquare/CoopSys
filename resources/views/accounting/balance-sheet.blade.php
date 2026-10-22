@extends('layouts.app')

@section('title', 'Balance Sheet')

@php
    $fmt = fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('M d, Y');
    $cur = $sheet['current'];
    $prev = $sheet['previous'];
    $cols = $sheet['compare'] ? 5 : 2;
@endphp

@section('content')
<x-page-header title="Balance Sheet" :subtitle="'As of ' . $fmt($asOf)">
    <button type="button" onclick="window.print()" class="btn btn-secondary"><span class="material-symbols-outlined text-[18px]">print</span> Print</button>
    <a href="{{ route('accounting.balance', ['as_of' => $asOf, 'compare' => $compare, 'export' => 'csv']) }}" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">download</span> Export Excel/CSV</a>
</x-page-header>

@include('accounting._nav')
@include('accounting._print-header', ['title' => 'Balance Sheet', 'subtitle' => 'As of ' . $fmt($asOf) . ($sheet['compare'] ? ' compared with ' . $fmt($sheet['compare_date']) : '')])

<x-card>
    <form method="GET" class="p-space-md flex flex-wrap items-end gap-3 no-print">
        <x-field label="As of date" name="as_of" type="date" :value="$asOf" />
        <x-select label="Compare with" name="compare" :value="$compare" :options="['none' => 'No comparison', 'lastmonth' => 'End of last month', 'yearend' => 'Last year-end', 'lastyear' => 'Same date last year']" />
        <button class="btn btn-primary">Apply</button>
    </form>

    <div class="overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Account</th>
                    <th class="num">{{ $fmt($asOf) }}</th>
                    @if ($sheet['compare']) <th class="num">{{ $fmt($sheet['compare_date']) }}</th><th class="num">Change</th><th class="num">%</th> @endif
                </tr>
            </thead>
            <tbody>
                @foreach (['asset' => ['ASSETS', 'Total assets', 'total_assets', 'assets'], 'liability' => ['LIABILITIES', 'Total liabilities', 'total_liabilities', 'liabilities'], 'equity' => ['EQUITY', 'Total equity', 'total_equity', 'equity']] as $key => [$title, $totalLabel, $totalKey, $changeKey])
                    <tr class="bg-surface-container-low font-bold"><td colspan="{{ $cols }}">{{ $title }}</td></tr>
                    @forelse ($sheet['sections'][$key] as $group)
                        <tr class="font-semibold"><td colspan="{{ $cols }}" class="pt-3">{{ $group['name'] }}</td></tr>
                        @foreach ($group['lines'] as $l)
                            <tr>
                                <td class="pl-6"><span class="font-mono text-xs text-outline mr-2">{{ $l['account']->code }}</span>{{ $l['account']->name }}</td>
                                <td class="num">@acct($l['current'])</td>
                                @if ($sheet['compare'])
                                    <td class="num text-on-surface-variant">@acct($l['previous'])</td>
                                    @include('accounting._change', ['change' => $l['change'], 'pct' => $l['pct'], 'good' => $key === 'liability' ? 'down' : 'up'])
                                @endif
                            </tr>
                        @endforeach
                        @if (count($sheet['sections'][$key]) > 1 || $key === 'equity')
                            <tr class="font-semibold">
                                <td class="text-right">Total {{ $group['name'] }}</td>
                                <td class="num">@acct($group['current'])</td>
                                @if ($sheet['compare'])
                                    <td class="num text-on-surface-variant">@acct($group['previous'])</td>
                                    @include('accounting._change', ['change' => $group['change'], 'pct' => $group['pct'], 'good' => $key === 'liability' ? 'down' : 'up'])
                                @endif
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="{{ $cols }}" class="text-on-surface-variant">None.</td></tr>
                    @endforelse

                    @if ($key === 'equity')
                        <tr class="font-semibold"><td colspan="{{ $cols }}" class="pt-3">Surplus not yet closed to equity</td></tr>
                        <tr>
                            <td class="pl-6">Earlier years</td><td class="num">@acct($cur['prior_surplus'])</td>
                            @if ($sheet['compare'])
                                <td class="num text-on-surface-variant">@acct($prev['prior_surplus'])</td>
                                @include('accounting._change', ['change' => $sheet['changes']['prior_surplus'], 'pct' => null])
                            @endif
                        </tr>
                        <tr>
                            <td class="pl-6">Current year to date</td><td class="num">@acct($cur['current_surplus'])</td>
                            @if ($sheet['compare'])
                                <td class="num text-on-surface-variant">@acct($prev['current_surplus'])</td>
                                @include('accounting._change', ['change' => $sheet['changes']['current_surplus'], 'pct' => null])
                            @endif
                        </tr>
                    @endif

                    <tr class="font-bold border-t-2 border-outline-variant">
                        <td class="text-right">{{ $totalLabel }}</td>
                        <td class="num">@acct($cur[$totalKey])</td>
                        @if ($sheet['compare'])
                            <td class="num text-on-surface-variant">@acct($prev[$totalKey])</td>
                            @include('accounting._change', ['change' => $sheet['changes'][$changeKey], 'pct' => $prev[$totalKey] != 0 ? round(($cur[$totalKey] - $prev[$totalKey]) / abs($prev[$totalKey]) * 100, 1) : null, 'good' => $key === 'liability' ? 'down' : 'up'])
                        @endif
                    </tr>
                @endforeach

                <tr class="bg-primary text-on-primary font-bold text-sm">
                    <td class="text-right">TOTAL LIABILITIES AND EQUITY</td>
                    <td class="num">@acct($cur['total_liabilities_equity'])</td>
                    @if ($sheet['compare']) <td class="num">@acct($prev['total_liabilities_equity'])</td><td></td><td></td> @endif
                </tr>
            </tbody>
        </table>
    </div>
    <div class="p-space-md text-sm font-semibold {{ $cur['balanced'] ? 'text-secondary' : 'text-error' }}">
        {{ $cur['balanced'] ? '✔ The balance sheet balances: assets equal liabilities plus equity.' : '✖ The balance sheet is out of balance by ₱' . number_format(abs($cur['total_assets'] - $cur['total_liabilities_equity']), 2) }}
    </div>
</x-card>
@endsection
