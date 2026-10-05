@extends('layouts.app')

@section('title', 'Ledger ' . $member->member_no)

@php
    $accountOf = fn ($t) => match (true) {
        in_array($t->type, ['savings_deposit', 'savings_withdrawal']) => 'Savings',
        in_array($t->type, ['share_deposit', 'share_withdrawal']) => 'Share capital',
        str_starts_with($t->type, 'loan_') => $t->loan?->loan_no ?? 'Loan',
        default => '—',
    };
@endphp

@section('content')
<x-page-header title="Member / Client Ledger" :subtitle="$member->full_name . ' · ' . $member->member_no">
    <a href="{{ route('members.show', $member) }}" class="btn btn-secondary">Back to member</a>
    <a href="{{ route('savings.statement', $member) }}" class="btn btn-secondary">Savings ledger</a>
    <a href="{{ route('share.ledger', $member) }}" class="btn btn-secondary">Share capital ledger</a>
    @allowed('collections', 'manage')
        <a href="{{ route('collections.create', ['member' => $member->id]) }}" class="btn btn-primary">Collect payment</a>
    @endallowed
    <button type="button" onclick="window.print()" class="btn btn-secondary"><span class="material-symbols-outlined text-[18px]">print</span> Print</button>
</x-page-header>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-gutter">
    <x-card class="p-space-md"><div class="field-label">Loan balance</div><div class="font-headline-md text-headline-md font-bold {{ $loanBalance > 0 ? 'text-error' : '' }}">@peso($loanBalance)</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Savings balance</div><div class="font-headline-md text-headline-md font-bold text-secondary">@peso($savings)</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Share capital paid</div><div class="font-headline-md text-headline-md font-bold text-secondary">@peso($shares)</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Share subscription</div><div class="font-headline-md text-headline-md font-bold">@peso($member->subscribed_shares)</div></x-card>
</div>

<x-card title="Loan Accounts">
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Loan</th><th>Product</th><th class="num">Principal</th><th class="num">Balance</th><th>Status</th><th class="no-print"></th></tr></thead>
            <tbody>
                @forelse ($loans as $loan)
                    <tr>
                        <td class="font-mono text-[12px] font-semibold text-primary">{{ $loan->loan_no }}</td>
                        <td>{{ $loan->product?->name ?? '—' }}</td>
                        <td class="num">@peso($loan->principal)</td>
                        <td class="num font-semibold">@peso($loan->balance)</td>
                        <td><span class="inline-flex px-2 py-0.5 rounded text-[11px] font-semibold {{ \App\Models\Loan::statusColor($loan->status) }}">{{ ucfirst($loan->status) }}</span></td>
                        <td class="no-print"><a href="{{ route('loans.ledger', $loan) }}" class="btn btn-secondary btn-sm">Loan ledger</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-on-surface-variant py-6">No loans.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-card>

<x-card title="Transactions">
    <form method="GET" class="px-space-md pb-space-sm flex flex-wrap items-end gap-3 no-print">
        <x-field label="From" name="from" type="date" :value="$from" />
        <x-field label="To" name="to" type="date" :value="$to" />
        <button class="btn btn-secondary">Show</button>
        @if ($from || $to) <a href="{{ route('members.ledger', $member) }}" class="btn btn-secondary">Clear</a> @endif
    </form>
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Date</th><th>Receipt / reference</th><th>Account</th><th>Particulars</th><th class="num">Received</th><th class="num">Paid out</th><th class="num">Balance after</th><th>Status</th></tr></thead>
            <tbody>
                @forelse ($transactions as $t)
                    <tr class="{{ $t->status === 'voided' ? 'opacity-50 line-through' : '' }}">
                        <td class="whitespace-nowrap">{{ $t->posted_at->format('M d, Y') }}</td>
                        <td class="font-mono text-[12px] font-semibold text-primary">
                            @if ($t->collection) <a href="{{ route('collections.show', $t->collection) }}">{{ $t->collection->receipt_no }}</a>
                            @else <a href="{{ route('transactions.show', $t) }}">{{ $t->reference_no }}</a> @endif
                        </td>
                        <td>{{ $accountOf($t) }}</td>
                        <td>{{ $t->label() }}{{ $t->remarks ? ' · ' . $t->remarks : '' }}</td>
                        <td class="num text-secondary font-semibold">{{ $t->direction === 'in' ? '₱' . number_format($t->amount, 2) : '' }}</td>
                        <td class="num font-semibold">{{ $t->direction === 'out' ? '₱' . number_format($t->amount, 2) : '' }}</td>
                        <td class="num">{{ $t->balance_after !== null ? '₱' . number_format($t->balance_after, 2) : '—' }}</td>
                        <td>{{ ucfirst($t->status) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-on-surface-variant py-6">No transactions.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-card>
@endsection
