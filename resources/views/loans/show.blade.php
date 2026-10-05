@extends('layouts.app')

@section('title', $loan->loan_no)

@section('content')
<x-page-header :title="'Loan ' . $loan->loan_no" :subtitle="$loan->member->full_name . ' · ' . $loan->member->member_no">
    <a href="{{ route('loans.index') }}" class="btn btn-secondary">Back</a>
    <a href="{{ route('loans.ledger', $loan) }}" class="btn btn-secondary">Loan ledger</a>
    <button type="button" onclick="window.print()" class="btn btn-secondary"><span class="material-symbols-outlined text-[18px]">print</span> Print</button>

    @if ($loan->status === 'pending')
        @allowed('loans', 'approve')
            <form method="POST" action="{{ route('loans.approve', $loan) }}">@csrf <button class="btn btn-success">Approve</button></form>
            <form method="POST" action="{{ route('loans.reject', $loan) }}" onsubmit="return confirm('Reject this loan application?')">@csrf <button class="btn btn-danger">Reject</button></form>
        @endallowed
    @endif
    @if ($loan->status === 'approved')
        @allowed('loans', 'disburse')
            <form method="POST" action="{{ route('loans.release', $loan) }}" onsubmit="return confirm('Release ₱{{ number_format($loan->principal, 2) }} cash to the member now?')">@csrf <button class="btn btn-primary">Release Loan</button></form>
        @endallowed
    @endif
</x-page-header>

<div class="grid grid-cols-1 md:grid-cols-4 gap-gutter">
    <x-card class="p-space-md"><div class="field-label">Status</div>
        <span class="inline-flex px-2 py-0.5 rounded text-[12px] font-semibold {{ \App\Models\Loan::statusColor($loan->status) }}">{{ ucfirst($loan->status) }}</span>
        @if ($loan->isPastDue()) <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-error-container text-on-error-container">PAST DUE</span> @endif
    </x-card>
    <x-card class="p-space-md"><div class="field-label">Principal</div><div class="font-headline-md text-headline-md font-bold">@peso($loan->principal)</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Total Payable</div><div class="font-headline-md text-headline-md font-bold">@peso($loan->total_payable)</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Balance</div><div class="font-headline-md text-headline-md font-bold {{ $loan->balance > 0 ? 'text-error' : 'text-secondary' }}">@peso($loan->balance)</div></x-card>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-gutter items-start">
    <x-card title="Loan Details">
        <dl class="p-space-md pt-0 grid grid-cols-2 gap-y-2 text-sm">
            <dt class="text-on-surface-variant">Loan product</dt><dd>@if ($loan->product)<a class="text-primary" href="{{ route('loan-products.show', $loan->product) }}">{{ $loan->product->name }}</a>@else — @endif</dd>
            <dt class="text-on-surface-variant">Interest rate</dt><dd>{{ (float) $loan->interest_rate }}% / year</dd>
            <dt class="text-on-surface-variant">Term</dt><dd>{{ $loan->term_value ?? $loan->term_months }} {{ strtolower(\App\Models\LoanProduct::label('term_units', $loan->term_unit)) }}</dd>
            <dt class="text-on-surface-variant">Payments</dt><dd>{{ $loan->number_of_payments ?: $loan->term_months }} {{ strtolower(\App\Models\LoanProduct::label('frequencies', $loan->payment_frequency)) }}</dd>
            <dt class="text-on-surface-variant">Total interest</dt><dd>@peso($loan->total_interest)</dd>
            @if ($loan->net_proceeds !== null)
                <dt class="text-on-surface-variant">Deductions at release</dt><dd>@peso($loan->deductions_total)</dd>
                <dt class="text-on-surface-variant">Net proceeds</dt><dd class="font-semibold">@peso($loan->net_proceeds)</dd>
                <dt class="text-on-surface-variant">Fees added to payable</dt><dd>@peso($loan->fees_added)</dd>
            @endif
            <dt class="text-on-surface-variant">Payment per schedule</dt><dd class="font-semibold">@peso($loan->monthly_amortization)</dd>
            <dt class="text-on-surface-variant">Purpose</dt><dd>{{ $loan->purpose ?: '—' }}</dd>
            <dt class="text-on-surface-variant">Applied</dt><dd>{{ $loan->applied_at->format('M d, Y') }}</dd>
            <dt class="text-on-surface-variant">Approved</dt><dd>{{ $loan->approved_at?->format('M d, Y') ?? '—' }}</dd>
            <dt class="text-on-surface-variant">Released</dt><dd>{{ $loan->released_at?->format('M d, Y') ?? '—' }}</dd>
            <dt class="text-on-surface-variant">Matures</dt><dd>{{ $loan->maturity_date?->format('M d, Y') ?? '—' }}</dd>
            <dt class="text-on-surface-variant">Paid so far</dt><dd class="text-secondary font-semibold">@peso($paidTotal)</dd>
        </dl>
    </x-card>

    <div class="lg:col-span-2 flex flex-col gap-gutter">
        @if ($loan->status === 'released')
            @allowed('receipts', 'manage')
            <x-card title="Receive Payment" subtitle="Posts a cash receipt and reduces the balance." class="no-print">
                <form method="POST" action="{{ route('receipts.store') }}" class="p-space-md pt-0 grid grid-cols-1 md:grid-cols-3 gap-4 items-start">
                    @csrf
                    <input type="hidden" name="type" value="loan_payment">
                    <input type="hidden" name="member_id" value="{{ $loan->member_id }}">
                    <input type="hidden" name="loan_id" value="{{ $loan->id }}">
                    <x-field label="Amount (₱)" name="amount" type="number" step="0.01" min="0.01" :max="$loan->balance" :value="min($loan->monthly_amortization, $loan->balance)" required />
                    <x-field label="Remarks" name="remarks" />
                    <div><button class="btn btn-primary">Receive payment</button></div>
                </form>
            </x-card>
            @endallowed
        @endif

        <x-card title="Payments Received">
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>Reference</th><th>Date</th><th class="num">Amount</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($payments as $t)
                            <tr>
                                <td class="font-mono text-[12px] font-semibold text-primary"><a href="{{ route('transactions.show', $t) }}">{{ $t->reference_no }}</a></td>
                                <td>{{ $t->posted_at->format('M d, Y') }}</td>
                                <td class="num font-bold text-secondary {{ $t->status === 'voided' ? 'line-through opacity-60' : '' }}">@peso($t->amount)</td>
                                <td>{{ ucfirst($t->status) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-on-surface-variant py-6">No payments yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        <x-card title="Payment Schedule" :subtitle="$loan->released_at ? 'Based on the release date' : 'Estimated: starts when the loan is released'">
            @if ($loan->number_of_payments > 0 && ! $loan->schedule)
                <p class="p-space-md pt-0 text-sm text-on-surface-variant">This loan product does not generate a payment schedule automatically.</p>
            @else
            <div class="overflow-x-auto max-h-96">
                <table class="data-table">
                    <thead><tr><th>#</th><th>Due date</th>@if ($loan->schedule)<th class="num">Principal</th><th class="num">Interest</th><th class="num">Fees</th>@endif<th class="num">Payment</th></tr></thead>
                    <tbody>
                        @foreach ($schedule as $row)
                            <tr>
                                <td>{{ $row['no'] }}</td><td>{{ $row['due']->format('M d, Y') }}</td>
                                @if ($loan->schedule)
                                    <td class="num">@peso($row['principal'])</td><td class="num">@peso($row['interest'])</td><td class="num">@peso($row['fees'])</td>
                                @endif
                                <td class="num font-semibold">@peso($row['payment'])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </x-card>
    </div>
</div>
@endsection
