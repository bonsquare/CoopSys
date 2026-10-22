@extends('layouts.app')

@section('title', 'Income Statement')

@php
    $fmt = fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('M d, Y');
    $period = $fmt($from) . ' to ' . $fmt($to);
    $cols = $is['compare'] ? 5 : 3;
@endphp

@section('content')
<x-page-header title="Income Statement" :subtitle="'For the period ' . $period">
    <button type="button" onclick="window.print()" class="btn btn-secondary"><span class="material-symbols-outlined text-[18px]">print</span> Print</button>
    <a href="{{ route('accounting.income', ['from' => $from, 'to' => $to, 'compare' => $compare, 'export' => 'csv']) }}" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">download</span> Export Excel/CSV</a>
</x-page-header>

@include('accounting._nav')
@include('accounting._print-header', ['title' => 'Income Statement', 'subtitle' => $period . ($is['compare'] ? ' compared with ' . $fmt($is['compare_range'][0]) . ' to ' . $fmt($is['compare_range'][1]) : '')])

<x-card>
    <form method="GET" class="p-space-md grid grid-cols-2 md:grid-cols-5 gap-3 items-end no-print">
        <x-field label="From" name="from" type="date" :value="$from" />
        <x-field label="To" name="to" type="date" :value="$to" />
        <x-select label="Compare with" name="compare" :value="$compare" :options="['none' => 'No comparison', 'previous' => 'The period just before', 'lastyear' => 'Same period last year']" class="col-span-2" />
        <button class="btn btn-primary justify-center">Apply</button>
    </form>
    <div class="px-space-md pb-space-sm flex flex-wrap gap-2 no-print">
        @foreach ($presets as $label => [$a, $b])
            <a href="{{ route('accounting.income', ['from' => $a->toDateString(), 'to' => $b->toDateString(), 'compare' => $compare]) }}" class="btn btn-secondary btn-sm">{{ $label }}</a>
        @endforeach
    </div>

    <div class="overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Account</th>
                    <th class="num">{{ $is['compare'] ? $fmt($from) . ' – ' . $fmt($to) : 'Amount' }}</th>
                    @if ($is['compare'])
                        <th class="num">{{ $fmt($is['compare_range'][0]) }} – {{ $fmt($is['compare_range'][1]) }}</th><th class="num">Change</th><th class="num">%</th>
                    @else
                        <th class="num">% of income</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach (['income' => ['INCOME', 'Total income', 'up'], 'expense' => ['EXPENSES', 'Total expenses', 'down']] as $key => [$title, $totalLabel, $good])
                    <tr class="bg-surface-container-low font-bold"><td colspan="{{ $cols }}">{{ $title }}</td></tr>
                    @forelse ($is['sections'][$key] as $group)
                        <tr class="font-semibold"><td colspan="{{ $cols }}" class="pt-3">{{ $group['name'] }}</td></tr>
                        @foreach ($group['lines'] as $l)
                            <tr>
                                <td class="pl-6"><span class="font-mono text-xs text-outline mr-2">{{ $l['account']->code }}</span>{{ $l['account']->name }}</td>
                                <td class="num">@acct($l['current'])</td>
                                @if ($is['compare'])
                                    <td class="num text-on-surface-variant">@acct($l['previous'])</td>
                                    @include('accounting._change', ['change' => $l['change'], 'pct' => $l['pct'], 'good' => $good])
                                @else
                                    <td class="num text-on-surface-variant">{{ $is['total_income'] != 0 ? round($l['current'] / $is['total_income'] * 100, 1) . '%' : '' }}</td>
                                @endif
                            </tr>
                        @endforeach
                        <tr class="font-semibold">
                            <td class="text-right">Total {{ $group['name'] }}</td>
                            <td class="num">@acct($group['current'])</td>
                            @if ($is['compare'])
                                <td class="num text-on-surface-variant">@acct($group['previous'])</td>
                                @include('accounting._change', ['change' => $group['change'], 'pct' => $group['pct'], 'good' => $good])
                            @else
                                <td></td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $cols }}" class="text-on-surface-variant">None in this period.</td></tr>
                    @endforelse
                    @php
                        $total = $key === 'income'
                            ? [$is['total_income'], $is['prev_income'], $is['income_change'], $is['income_pct']]
                            : [$is['total_expenses'], $is['prev_expenses'], $is['expense_change'], $is['expense_pct']];
                    @endphp
                    <tr class="font-bold border-t-2 border-outline-variant">
                        <td class="text-right">{{ $totalLabel }}</td>
                        <td class="num">@acct($total[0])</td>
                        @if ($is['compare'])
                            <td class="num text-on-surface-variant">@acct($total[1])</td>
                            @include('accounting._change', ['change' => $total[2], 'pct' => $total[3], 'good' => $good])
                        @else
                            <td class="num text-on-surface-variant">{{ $key === 'expense' && $is['total_income'] != 0 ? round($total[0] / $is['total_income'] * 100, 1) . '%' : '' }}</td>
                        @endif
                    </tr>
                @endforeach

                <tr class="bg-primary text-on-primary font-bold text-sm">
                    <td class="text-right">NET {{ $is['net'] >= 0 ? 'SURPLUS' : 'DEFICIT' }}</td>
                    <td class="num">@acct($is['net'])</td>
                    @if ($is['compare'])
                        <td class="num">@acct($is['prev_net'])</td>
                        <td class="num">{{ ($is['net_change'] > 0 ? '+' : '') . number_format($is['net_change'], 2) }}</td>
                        <td class="num">{{ $is['net_pct'] === null ? '' : (($is['net_pct'] > 0 ? '+' : '') . $is['net_pct'] . '%') }}</td>
                    @else
                        <td class="num">{{ $is['total_income'] != 0 ? round($is['net'] / $is['total_income'] * 100, 1) . '%' : '' }}</td>
                    @endif
                </tr>
            </tbody>
        </table>
    </div>
    <p class="p-space-md text-xs text-outline">Cash basis: interest and fees count as income when collected or deducted at release. Year-end closing entries are not part of the income statement.</p>
</x-card>
@endsection
