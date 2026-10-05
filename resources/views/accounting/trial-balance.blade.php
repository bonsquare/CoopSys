@extends('layouts.app')

@section('title', 'Trial Balance')

@section('content')
<x-page-header title="Trial Balance" :subtitle="'As of ' . \Illuminate\Support\Carbon::parse($asOf)->format('F d, Y')">
    <button type="button" onclick="window.print()" class="btn btn-secondary"><span class="material-symbols-outlined text-[18px]">print</span> Print</button>
    <a href="{{ route('accounting.trial', ['as_of' => $asOf, 'hide_zero' => $hideZero ? 1 : 0, 'export' => 'csv']) }}" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">download</span> Export Excel/CSV</a>
</x-page-header>

@include('accounting._nav')

<div class="hidden print:block text-center">
    <div class="text-lg font-bold">{{ \App\Models\Setting::get('coop_name') }}</div>
    <div>{{ \App\Models\Setting::get('branch_name') }}</div>
    <div class="font-semibold">Trial Balance as of {{ \Illuminate\Support\Carbon::parse($asOf)->format('F d, Y') }}</div>
</div>

<x-card>
    <form method="GET" class="p-space-md flex flex-wrap items-end gap-3 no-print">
        <x-field label="As of date" name="as_of" type="date" :value="$asOf" />
        <label class="flex items-center gap-2 text-sm pb-2"><input type="checkbox" name="hide_zero" value="1" @checked($hideZero)> Hide accounts with no activity</label>
        <button class="btn btn-secondary">Apply</button>
    </form>

    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Code</th><th>Account</th><th>Type</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
            <tbody>
                @foreach ($tb['rows'] as $r)
                    <tr class="{{ $r['account']->is_active ? '' : 'opacity-50' }}">
                        <td class="font-mono">{{ $r['account']->code }}</td>
                        <td class="font-medium"><a href="{{ route('accounting.ledger', ['account' => $r['account']->id]) }}" class="hover:underline">{{ $r['account']->name }}</a></td>
                        <td>{{ \App\Models\Account::TYPES[$r['account']->type] }}</td>
                        <td class="num">{{ $r['tb_debit'] > 0 ? '₱' . number_format($r['tb_debit'], 2) : '' }}</td>
                        <td class="num">{{ $r['tb_credit'] > 0 ? '₱' . number_format($r['tb_credit'], 2) : '' }}</td>
                    </tr>
                @endforeach
                <tr class="font-bold bg-surface-container-low">
                    <td colspan="3" class="text-right">Totals</td>
                    <td class="num">@peso($tb['total_debit'])</td>
                    <td class="num">@peso($tb['total_credit'])</td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="p-space-md text-sm font-semibold {{ $tb['balanced'] ? 'text-secondary' : 'text-error' }}">
        {{ $tb['balanced'] ? '✔ The trial balance agrees: total debits equal total credits.' : '✖ Out of balance by ₱' . number_format(abs($tb['total_debit'] - $tb['total_credit']), 2) }}
    </div>
</x-card>
@endsection
