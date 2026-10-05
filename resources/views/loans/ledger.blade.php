@extends('layouts.app')

@section('title', 'Loan ledger ' . $loan->loan_no)

@php
    $partLabel = ['loan_principal' => 'Principal', 'loan_interest' => 'Interest', 'loan_fees' => 'Fees', 'loan_penalty' => 'Penalty', 'loan_payment' => 'Payment'];
@endphp

@section('content')
<x-page-header :title="'Loan Ledger ' . $loan->loan_no" :subtitle="$loan->member->full_name . ' · ' . ($loan->product?->name ?? 'Loan') . ' · ' . ucfirst($loan->status)">
    <a href="{{ route('loans.show', $loan) }}" class="btn btn-secondary">Loan details</a>
    <a href="{{ route('members.ledger', $loan->member) }}" class="btn btn-secondary">Member ledger</a>
    @if ($loan->status === 'released')
        @allowed('collections', 'manage')
            <a href="{{ route('collections.create', ['member' => $loan->member_id]) }}" class="btn btn-primary">Collect payment</a>
        @endallowed
    @endif
    <button type="button" onclick="window.print()" class="btn btn-secondary"><span class="material-symbols-outlined text-[18px]">print</span> Print</button>
</x-page-header>

<div class="grid grid-cols-2 lg:grid-cols-5 gap-gutter">
    <x-card class="p-space-md"><div class="field-label">Loan balance</div><div class="font-headline-md text-headline-md font-bold">@peso($loan->balance)</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Principal balance</div><div class="font-headline-md text-headline-md font-bold">@peso($loan->principalBalance())</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Interest balance</div><div class="font-headline-md text-headline-md font-bold">@peso($loan->interestBalance())</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Fees balance</div><div class="font-headline-md text-headline-md font-bold">@peso($loan->feesBalance())</div></x-card>
    <x-card class="p-space-md {{ $a && $a['penalty']['due'] > 0 ? 'bg-error-container' : '' }}">
        <div class="field-label">Penalty due</div>
        <div class="font-headline-md text-headline-md font-bold {{ $a && $a['penalty']['due'] > 0 ? 'text-error' : '' }}">@peso($a['penalty']['due'] ?? 0)</div>
        @if ($a && $a['penalty']['missed'])<div class="text-[11px] text-error">{{ $a['penalty']['missed'] }} missed installment(s)</div>@endif
    </x-card>
</div>

@if ($a)
<x-card title="Payment Schedule" :subtitle="'As of ' . now()->format('M d, Y') . '. Penalty starts after ' . ($loan->product?->grace_days ?? 0) . ' grace day(s).'">
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>#</th><th>Due date</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Fees</th><th class="num">Installment</th><th>Status</th></tr></thead>
            <tbody>
                @foreach ($a['rows'] as $row)
                    <tr class="{{ $row['overdue'] ? 'bg-error-container/40' : '' }}">
                        <td>{{ $row['no'] }}</td>
                        <td class="whitespace-nowrap">{{ $row['due']->format('M d, Y') }}</td>
                        <td class="num">@peso($row['principal'])</td>
                        <td class="num">@peso($row['interest'])</td>
                        <td class="num">@peso($row['fees'])</td>
                        <td class="num font-semibold">@peso($row['payment'])</td>
                        <td>
                            @if ($row['covered']) <span class="text-secondary font-semibold">Paid</span>
                            @elseif ($row['overdue']) <span class="text-error font-semibold">Overdue · short @peso($row['shortfall'])</span>
                            @elseif ($row['shortfall'] < $row['payment']) <span class="text-primary font-semibold">Partly paid</span>
                            @else <span class="text-on-surface-variant">Upcoming</span> @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-card>
@endif

<x-card title="Payments and Charges">
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Date</th><th>Receipt / reference</th><th>Particulars</th><th class="num">Amount</th><th class="num">Balance after</th><th>Collected by</th><th>Status</th></tr></thead>
            <tbody>
                @forelse ($transactions as $t)
                    <tr class="{{ $t->status === 'voided' ? 'opacity-50 line-through' : '' }}">
                        <td class="whitespace-nowrap">{{ $t->posted_at->format('M d, Y') }}</td>
                        <td class="font-mono text-[12px] font-semibold text-primary">
                            @if ($t->collection) <a href="{{ route('collections.show', $t->collection) }}">{{ $t->collection->receipt_no }}</a>
                            @else <a href="{{ route('transactions.show', $t) }}">{{ $t->reference_no }}</a> @endif
                        </td>
                        <td>{{ $partLabel[$t->type] ?? $t->label() }}</td>
                        <td class="num font-semibold {{ $t->direction === 'in' ? 'text-secondary' : '' }}">{{ $t->direction === 'in' ? '+' : '-' }}@peso($t->amount)</td>
                        <td class="num">{{ $t->balance_after !== null ? '₱' . number_format($t->balance_after, 2) : '—' }}</td>
                        <td>{{ $t->user?->name }}</td>
                        <td>{{ ucfirst($t->status) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-on-surface-variant py-6">No transactions on this loan yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="p-space-md text-[11px] text-outline">For a penalty line, "balance after" is the penalty still due. For the other lines it is the loan balance.</p>
</x-card>
@endsection
