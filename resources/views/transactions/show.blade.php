@extends('layouts.app')

@section('title', $t->reference_no)

@section('content')
<x-page-header :title="$t->reference_no" :subtitle="$t->label() . ' · ' . $t->posted_at->format('M d, Y h:i A')">
    <a href="{{ route('transactions.index') }}" class="btn btn-secondary">Back to ledger</a>
    <a href="{{ route('transactions.receipt', $t) }}" class="btn btn-secondary"><span class="material-symbols-outlined text-[18px]">receipt</span> Slip</a>
    <button type="button" onclick="window.print()" class="btn btn-secondary"><span class="material-symbols-outlined text-[18px]">print</span> Print</button>
    @if ($t->status === 'completed' && $t->type !== 'loan_release')
        @allowed('ledger', 'void')
            <form method="POST" action="{{ route('transactions.void', $t) }}" class="flex gap-2 items-center"
                  onsubmit="return confirm('Void this transaction? This reverses its accounting entry.')">
                @csrf
                <input name="reason" required maxlength="150" placeholder="Reason for voiding" class="input w-56">
                <button class="btn btn-danger">Void</button>
            </form>
        @endallowed
    @endif
</x-page-header>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-gutter items-start">
    <x-card title="Details">
        <dl class="p-space-md pt-0 grid grid-cols-3 gap-y-2 text-sm">
            <dt class="text-on-surface-variant">Status</dt>
            <dd class="col-span-2 font-semibold {{ $t->status === 'completed' ? 'text-secondary' : 'text-error' }}">{{ ucfirst($t->status) }}</dd>
            <dt class="text-on-surface-variant">Type</dt><dd class="col-span-2">{{ $t->label() }}</dd>
            <dt class="text-on-surface-variant">Amount</dt>
            <dd class="col-span-2 text-lg font-bold {{ $t->direction === 'in' ? 'text-secondary' : '' }}">{{ $t->direction === 'in' ? '+' : '-' }}@peso($t->amount)</dd>
            <dt class="text-on-surface-variant">Member</dt>
            <dd class="col-span-2">@if ($t->member)<a class="text-primary" href="{{ route('members.show', $t->member) }}">{{ $t->member->full_name }}</a> ({{ $t->member->member_no }})@else — @endif</dd>
            <dt class="text-on-surface-variant">Loan</dt>
            <dd class="col-span-2">@if ($t->loan)<a class="text-primary" href="{{ route('loans.show', $t->loan) }}">{{ $t->loan->loan_no }}</a>@else — @endif</dd>
            <dt class="text-on-surface-variant">Handled by</dt><dd class="col-span-2">{{ $t->user?->name ?? 'System' }} {{ $t->user ? '(' . $t->user->roleLabel() . ')' : '' }}</dd>
            <dt class="text-on-surface-variant">Remarks</dt><dd class="col-span-2">{{ $t->remarks ?: '—' }}</dd>
        </dl>
    </x-card>

    <x-card title="Accounting Entry" subtitle="Double-entry journal created automatically">
        @if ($t->journalEntry)
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>Account</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
                    <tbody>
                        @foreach ($t->journalEntry->lines as $line)
                            <tr>
                                <td>{{ $line->account->code }} · {{ $line->account->name }}</td>
                                <td class="num">{{ $line->debit > 0 ? '₱' . number_format($line->debit, 2) : '' }}</td>
                                <td class="num">{{ $line->credit > 0 ? '₱' . number_format($line->credit, 2) : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="p-space-md pt-0 text-sm text-on-surface-variant">No journal entry.</p>
        @endif
    </x-card>
</div>
@endsection
