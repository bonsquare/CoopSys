@extends('layouts.app')

@section('title', $member->full_name)

@section('content')
<x-page-header :title="$member->full_name" :subtitle="$member->member_no . ' · Savings ' . $member->savings_account_no . ' · ' . ucfirst($member->status) . ' · joined ' . $member->date_joined->format('M d, Y')">
    <a href="{{ route('members.index') }}" class="btn btn-secondary">Back</a>
    <a href="{{ route('members.ledger', $member) }}" class="btn btn-secondary">Member ledger</a>
    <a href="{{ route('savings.statement', $member) }}" class="btn btn-secondary">Savings statement</a>
    @allowed('collections', 'manage')
        <a href="{{ route('collections.create', ['member' => $member->id]) }}" class="btn btn-primary">Collect payment</a>
    @endallowed
    <button type="button" onclick="window.print()" class="btn btn-secondary"><span class="material-symbols-outlined text-[18px]">print</span> Print</button>
    @allowed('members', 'manage')
        <a href="{{ route('members.edit', $member) }}" class="btn btn-secondary">Edit</a>
        <form method="POST" action="{{ route('members.toggle', $member) }}" onsubmit="return confirm('Change this member\'s status?')">
            @csrf
            <button class="btn {{ $member->status === 'active' ? 'btn-danger' : 'btn-success' }}">{{ $member->status === 'active' ? 'Make Inactive' : 'Make Active' }}</button>
        </form>
    @endallowed
</x-page-header>

<div class="grid grid-cols-1 md:grid-cols-4 gap-gutter">
    @foreach ([
        ['Subscribed Shares', $member->subscribed_shares, ''],
        ['Paid-up Shares', $paidUp, 'text-secondary'],
        ['Savings Balance', $savings, ''],
        ['Loan Balance', $loanBalance, $loanBalance > 0 ? 'text-error' : ''],
    ] as [$label, $value, $color])
        <x-card class="p-space-md">
            <div class="font-label-sm text-label-sm text-on-surface-variant uppercase tracking-wider font-semibold">{{ $label }}</div>
            <div class="font-headline-md text-headline-md font-bold mt-1 {{ $color }}">@peso($value)</div>
        </x-card>
    @endforeach
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-gutter">
    <x-card title="Profile">
        <dl class="p-space-md pt-0 grid grid-cols-3 gap-y-2 text-sm">
            <dt class="text-on-surface-variant">Gender</dt><dd class="col-span-2">{{ $member->gender ?: '—' }}</dd>
            <dt class="text-on-surface-variant">Birthdate</dt><dd class="col-span-2">{{ $member->birthdate?->format('M d, Y') ?: '—' }}</dd>
            <dt class="text-on-surface-variant">Contact</dt><dd class="col-span-2">{{ $member->contact_no ?: '—' }}</dd>
            <dt class="text-on-surface-variant">Email</dt><dd class="col-span-2 break-all">{{ $member->email ?: '—' }}</dd>
            <dt class="text-on-surface-variant">Occupation</dt><dd class="col-span-2">{{ $member->occupation ?: '—' }}</dd>
            <dt class="text-on-surface-variant">Address</dt><dd class="col-span-2">{{ $member->address ?: '—' }}</dd>
        </dl>
    </x-card>

    <x-card title="Loans" class="lg:col-span-2">
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr><th>Loan No.</th><th class="num">Principal</th><th class="num">Balance</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($member->loans as $loan)
                        <tr>
                            <td class="font-mono text-[12px] font-semibold text-primary">{{ $loan->loan_no }}</td>
                            <td class="num">@peso($loan->principal)</td>
                            <td class="num">@peso($loan->balance)</td>
                            <td><span class="inline-flex px-2 py-0.5 rounded text-xs font-semibold {{ \App\Models\Loan::statusColor($loan->status) }}">{{ ucfirst($loan->status) }}</span></td>
                            <td class="no-print"><a href="{{ route('loans.show', $loan) }}" class="btn btn-secondary btn-sm">View</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-on-surface-variant py-6">No loans.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>

<x-card title="Recent Transactions" subtitle="Latest 15 for this member">
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Reference</th><th>Date</th><th>Type</th><th class="num">Amount</th><th>Status</th></tr></thead>
            <tbody>
                @forelse ($transactions as $t)
                    <tr>
                        <td class="font-mono text-[12px] font-semibold text-primary"><a href="{{ route('transactions.show', $t) }}">{{ $t->reference_no }}</a></td>
                        <td>{{ $t->posted_at->format('M d, Y') }}</td>
                        <td><span class="inline-flex px-2 py-0.5 rounded text-xs font-semibold {{ $t->badge() }}">{{ $t->label() }}</span></td>
                        <td class="num font-bold {{ $t->direction === 'in' ? 'text-secondary' : '' }}">{{ $t->direction === 'in' ? '+' : '-' }}@peso($t->amount)</td>
                        <td>{{ ucfirst($t->status) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-on-surface-variant py-6">No transactions yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-card>
@endsection
