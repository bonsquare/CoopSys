@extends('layouts.app')

@section('title', 'Slip ' . $t->reference_no)

@php
    $methods = config('coop.payment_methods');
    $balanceLabel = match ($t->type) {
        'savings_deposit', 'savings_withdrawal' => 'Savings balance after',
        'loan_payment' => 'Loan balance after',
        'share_deposit' => 'Share capital paid after',
        default => 'Balance after',
    };
@endphp

@section('content')
<x-page-header title="Transaction Slip" :subtitle="$t->reference_no">
    <button type="button" onclick="window.print()" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">print</span> Print slip</button>
    @if (in_array($t->type, ['savings_deposit', 'savings_withdrawal', 'loan_payment']))
        <a href="{{ route('savings.index') }}" class="btn btn-secondary">New transaction</a>
    @endif
    @if ($t->member && in_array($t->type, ['savings_deposit', 'savings_withdrawal']))
        <a href="{{ route('savings.statement', $t->member) }}" class="btn btn-secondary">Statement</a>
    @endif
    <a href="{{ route('transactions.show', $t) }}" class="btn btn-secondary">Details</a>
</x-page-header>

<div class="bg-surface-container-lowest rounded-xl shadow-sm max-w-md w-full mx-auto p-space-lg relative">
    @if ($t->status === 'voided')
        <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
            <span class="text-error/30 text-6xl font-black -rotate-12 border-4 border-error/30 px-4 rounded-lg">VOID</span>
        </div>
    @endif

    <div class="text-center border-b border-dashed border-outline-variant pb-3">
        <div class="font-headline-sm text-headline-sm font-bold text-primary">{{ \App\Models\Setting::get('coop_name') }}</div>
        <div class="text-[12px] text-on-surface-variant">{{ \App\Models\Setting::get('branch_name') }}</div>
        <div class="mt-2 font-label-md text-label-md uppercase tracking-wider font-semibold">{{ $t->label() }} slip</div>
    </div>

    <dl class="grid grid-cols-2 gap-y-2 text-sm py-3 border-b border-dashed border-outline-variant">
        <dt class="text-on-surface-variant">Reference</dt><dd class="text-right font-mono font-semibold">{{ $t->reference_no }}</dd>
        <dt class="text-on-surface-variant">Date &amp; time</dt><dd class="text-right">{{ $t->posted_at->format('M d, Y h:i A') }}</dd>
        @if ($t->member)
            <dt class="text-on-surface-variant">Member</dt><dd class="text-right">{{ $t->member->full_name }}</dd>
            <dt class="text-on-surface-variant">Account no.</dt><dd class="text-right font-mono">{{ $t->member->savings_account_no }}</dd>
        @endif
        @if ($t->loan)
            <dt class="text-on-surface-variant">Loan</dt><dd class="text-right font-mono">{{ $t->loan->loan_no }}</dd>
        @endif
        <dt class="text-on-surface-variant">Method</dt>
        <dd class="text-right">{{ $methods[$t->payment_method] ?? ucfirst($t->payment_method) }}{{ $t->payment_ref ? ' · ' . $t->payment_ref : '' }}</dd>
        @if ($t->remarks)
            <dt class="text-on-surface-variant">Remarks</dt><dd class="text-right">{{ $t->remarks }}</dd>
        @endif
    </dl>

    <div class="py-4 text-center border-b border-dashed border-outline-variant">
        <div class="field-label">Amount</div>
        <div class="text-3xl font-black {{ $t->direction === 'in' ? 'text-secondary' : 'text-on-surface' }}">@peso($t->amount)</div>
        <div class="text-xs text-outline">{{ $t->direction === 'in' ? 'Received' : 'Paid out' }}</div>
    </div>

    @if ($t->balance_after !== null)
        <div class="flex justify-between py-3 text-sm border-b border-dashed border-outline-variant">
            <span class="text-on-surface-variant">{{ $balanceLabel }}</span><strong>@peso($t->balance_after)</strong>
        </div>
    @endif

    <div class="grid grid-cols-2 gap-6 pt-8 text-center text-xs text-on-surface-variant">
        <div><div class="border-t border-on-surface pt-1">Teller: {{ $t->user?->name ?? 'System' }}</div></div>
        <div><div class="border-t border-on-surface pt-1">Member signature</div></div>
    </div>

    <p class="text-center text-xs text-outline mt-4">Keep this slip as proof of your transaction.</p>
</div>
@endsection
