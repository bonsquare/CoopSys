@extends('layouts.app')

@section('title', 'Statement ' . $member->member_no)

@section('content')
<x-page-header title="Statement of Account" :subtitle="$member->full_name . ' · ' . $member->member_no">
    <a href="{{ route('savings.index') }}" class="btn btn-secondary">Back to teller</a>
    <button type="button" onclick="window.print()" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">print</span> Print statement</button>
</x-page-header>

<x-card>
    <form method="GET" class="p-space-md flex flex-wrap items-end gap-3 no-print">
        <x-field label="From" name="from" type="date" :value="$from" />
        <x-field label="To" name="to" type="date" :value="$to" />
        <button class="btn btn-secondary">Show</button>
    </form>
</x-card>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-gutter">
    <x-card class="p-space-md"><div class="field-label">Opening balance</div><div class="font-headline-md text-headline-md font-bold">@peso($opening)</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Total deposits</div><div class="font-headline-md text-headline-md font-bold text-secondary">@peso($deposits)</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Total withdrawals</div><div class="font-headline-md text-headline-md font-bold">@peso($withdrawals)</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Closing balance</div><div class="font-headline-md text-headline-md font-bold text-primary">@peso($closing)</div></x-card>
</div>

<x-card :title="'Transactions ' . \Illuminate\Support\Carbon::parse($from)->format('M d, Y') . ' to ' . \Illuminate\Support\Carbon::parse($to)->format('M d, Y')">
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Date</th><th>Reference</th><th>Particulars</th><th class="num">Deposit</th><th class="num">Withdrawal</th><th class="num">Balance</th><th class="no-print"></th></tr></thead>
            <tbody>
                <tr class="bg-surface-container-low">
                    <td colspan="5" class="font-semibold">Opening balance</td>
                    <td class="num font-semibold">@peso($opening)</td><td class="no-print"></td>
                </tr>
                @forelse ($lines as $line)
                    @php $t = $line['tx']; @endphp
                    <tr>
                        <td class="whitespace-nowrap">{{ $t->posted_at->format('M d, Y') }}</td>
                        <td class="font-mono text-[12px] font-semibold text-primary">{{ $t->reference_no }}</td>
                        <td>{{ $t->label() }}{{ $t->remarks ? ' · ' . $t->remarks : '' }}</td>
                        <td class="num text-secondary font-semibold">{{ $t->type === 'savings_deposit' ? '₱' . number_format($t->amount, 2) : '' }}</td>
                        <td class="num font-semibold">{{ $t->type === 'savings_withdrawal' ? '₱' . number_format($t->amount, 2) : '' }}</td>
                        <td class="num">@peso($line['balance'])</td>
                        <td class="no-print"><a href="{{ route('transactions.receipt', $t) }}" class="btn btn-secondary btn-sm">Slip</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-on-surface-variant py-6">No transactions in this period.</td></tr>
                @endforelse
                <tr class="bg-surface-container-low font-bold">
                    <td colspan="5">Closing balance</td><td class="num">@peso($closing)</td><td class="no-print"></td>
                </tr>
            </tbody>
        </table>
    </div>
</x-card>
@endsection
