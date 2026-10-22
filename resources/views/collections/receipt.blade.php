@extends('layouts.app')

@section('title', 'Receipt ' . $c->receipt_no)

@php
    $methods = config('coop.payment_methods');
    $out = $c->direction === 'out';
    $placeOf = fn ($line) => match ($line->meta()['kind'] ?? 'other') {
        'loan' => $line->loan?->loan_no ?? 'Loan',
        'savings' => 'Regular savings',
        'share' => 'Share capital',
        default => '—',
    };
@endphp

@section('content')
<x-page-header :title="'Receipt ' . $c->receipt_no" :subtitle="$c->typeLabel()">
    <button type="button" onclick="window.print()" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">print</span> Print receipt</button>
    <a href="{{ route('collections.show', $c) }}" class="btn btn-secondary">Back to transaction</a>
    @allowed('collections', 'manage')
        <a href="{{ route('collections.create') }}" class="btn btn-secondary">New transaction</a>
    @endallowed
</x-page-header>

<div class="bg-surface-container-lowest rounded-xl shadow-sm max-w-lg w-full mx-auto p-space-lg relative">
    @if ($c->status === 'cancelled')
        <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
            <span class="text-error/30 text-5xl font-black -rotate-12 border-4 border-error/30 px-4 rounded-lg">CANCELLED</span>
        </div>
    @elseif ($c->status === 'draft')
        <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
            <span class="text-outline/40 text-5xl font-black -rotate-12 border-4 border-outline/40 px-4 rounded-lg">DRAFT</span>
        </div>
    @endif

    <div class="text-center border-b border-dashed border-outline-variant pb-3">
        <div class="font-headline-sm text-headline-sm font-bold text-primary">{{ \App\Models\Setting::get('coop_name') }}</div>
        <div class="text-[12px] text-on-surface-variant">{{ \App\Models\Setting::get('branch_name') }}</div>
        <div class="mt-2 font-label-md text-label-md uppercase tracking-wider font-semibold">{{ $out ? 'Payout receipt' : 'Official receipt' }}</div>
    </div>

    <dl class="grid grid-cols-2 gap-y-1 text-sm py-3 border-b border-dashed border-outline-variant">
        <dt class="text-on-surface-variant">Receipt no.</dt><dd class="text-right font-mono font-bold">{{ $c->receipt_no }}</dd>
        <dt class="text-on-surface-variant">Date &amp; time</dt><dd class="text-right">{{ ($c->posted_at ?? $c->collection_date)->format('M d, Y') }}{{ $c->posted_at ? ' ' . $c->posted_at->format('h:i A') : '' }}</dd>
        <dt class="text-on-surface-variant">Member / client</dt><dd class="text-right">{{ $c->member->full_name }}</dd>
        <dt class="text-on-surface-variant">Member ID</dt><dd class="text-right font-mono">{{ $c->member->member_no }}</dd>
        <dt class="text-on-surface-variant">Teller / cashier</dt><dd class="text-right">{{ $c->collector?->name ?? '—' }}</dd>
        <dt class="text-on-surface-variant">Payment method</dt><dd class="text-right">{{ $methods[$c->payment_method] ?? $c->payment_method }}{{ $c->payment_ref ? ' · ' . $c->payment_ref : '' }}</dd>
        @if ($c->remarks)
            <dt class="text-on-surface-variant">Remarks</dt><dd class="text-right">{{ $c->remarks }}</dd>
        @endif
    </dl>

    <table class="w-full text-sm my-3">
        <thead>
            <tr class="text-xs uppercase tracking-wider text-on-surface-variant text-left"><th class="py-1">Breakdown</th><th>Account</th><th class="text-right">Amount</th></tr>
        </thead>
        <tbody>
            @foreach ($c->lines as $line)
                <tr class="border-t border-dotted border-outline-variant">
                    <td class="py-1">{{ $line->label() }}</td>
                    <td class="text-on-surface-variant">{{ $placeOf($line) }}</td>
                    <td class="text-right tabular-nums">{{ $line->isOut() ? '(' : '' }}@peso($line->amount){{ $line->isOut() ? ')' : '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="border-t border-dashed border-outline-variant pt-3 text-sm">
        <div class="flex justify-between text-base font-bold">
            <span>{{ $out ? 'Total amount paid out' : 'Total amount received' }}</span><span>@peso($c->total_amount)</span>
        </div>
        @if (! $out && $c->payment_method === 'cash' && $c->status !== 'draft')
            <div class="flex justify-between"><span class="text-on-surface-variant">Cash received</span><span>@peso($c->cash_tendered ?? $c->total_amount)</span></div>
            <div class="flex justify-between"><span class="text-on-surface-variant">Change due</span><span>@peso($c->change_due)</span></div>
        @endif
    </div>

    @if ($c->status === 'cancelled')
        <p class="mt-3 text-[12px] text-error">Cancelled {{ $c->cancelled_at?->format('M d, Y h:i A') }} by {{ $c->canceller?->name }}: {{ $c->cancel_reason }}</p>
    @endif

    <div class="grid grid-cols-2 gap-6 pt-10 text-center text-xs text-on-surface-variant">
        <div><div class="border-t border-on-surface pt-1">Teller / cashier</div></div>
        <div><div class="border-t border-on-surface pt-1">Member / client signature</div></div>
    </div>

    <p class="text-center text-xs text-outline mt-4">Printed by {{ auth()->user()->name }} on {{ now()->format('M d, Y h:i A') }}</p>
</div>
@endsection
