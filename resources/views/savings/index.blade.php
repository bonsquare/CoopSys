@extends('layouts.app')

@section('title', 'Deposits & Withdrawals')

@section('content')
<x-page-header title="Teller Window" subtitle="Deposits, withdrawals and loan payments. Choose a member, then the transaction." />

<div class="grid grid-cols-2 lg:grid-cols-4 gap-gutter">
    <x-card class="p-space-md"><div class="field-label">Deposits today</div><div class="font-headline-md text-headline-md font-bold text-secondary">@peso($todayDeposits)</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Withdrawals today</div><div class="font-headline-md text-headline-md font-bold">@peso($todayWithdrawals)</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Loan payments today</div><div class="font-headline-md text-headline-md font-bold text-primary">@peso($todayPayments)</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Total savings held</div><div class="font-headline-md text-headline-md font-bold">@peso($total)</div></x-card>
</div>

<div class="grid grid-cols-1 lg:grid-cols-5 gap-gutter items-start">

    {{-- Transaction form --}}
    <x-card class="lg:col-span-3 no-print">
        @if (empty($kinds))
            <p class="p-space-md text-sm text-on-surface-variant">Your role can view accounts but cannot post transactions.</p>
        @else
            {{-- Tabs --}}
            <div class="flex flex-wrap gap-1 p-space-md pb-0" id="teller-tabs" role="tablist">
                @foreach ($kinds as $key => $text)
                    <button type="button" data-kind="{{ $key }}" role="tab"
                            class="teller-tab px-space-md py-2 rounded-t-lg font-label-md text-label-md border-b-2 border-transparent text-on-surface-variant hover:text-on-surface">
                        {{ $text }}
                    </button>
                @endforeach
            </div>

            <form method="POST" action="{{ route('savings.store') }}" id="teller-form" class="p-space-md grid grid-cols-1 md:grid-cols-2 gap-4 border-t border-outline-variant"
                  data-account-url="{{ route('savings.account', ['member' => '__ID__']) }}"
                  data-initial-kind="{{ old('kind', array_key_first($kinds)) }}"
                  data-initial-member="{{ old('member_id') }}"
                  data-initial-loan="{{ old('loan_id') }}"
                  data-kinds='@json($kinds)'>
                @csrf
                <input type="hidden" name="kind" id="kind" value="{{ old('kind', array_key_first($kinds)) }}">

                {{-- Member lookup --}}
                <div class="md:col-span-2">
                    <label class="field-label" for="member-search">Member</label>
                    <input type="search" id="member-search" placeholder="Type a name or member number to search…" class="input mb-2" autocomplete="off">
                    <select id="member_id" name="member_id" required class="input" size="1">
                        <option value="">Choose member…</option>
                        @foreach ($options as $id => $text)
                            <option value="{{ $id }}" @selected((string) old('member_id') === (string) $id)>{{ $text }}</option>
                        @endforeach
                    </select>
                    @error('member_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                {{-- Loan (loan payment only) --}}
                <div class="md:col-span-2 hidden" id="loan-box">
                    <label class="field-label" for="loan_id">Loan to pay</label>
                    <select id="loan_id" name="loan_id" class="input"><option value="">Choose a member first</option></select>
                    @error('loan_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                {{-- Amount --}}
                <div>
                    <label class="field-label" for="amount">Amount (₱)</label>
                    <input id="amount" name="amount" type="number" step="0.01" min="0.01" required value="{{ old('amount') }}" class="input text-lg font-semibold" placeholder="0.00">
                    @error('amount') <p class="field-error">{{ $message }}</p> @enderror
                    <div class="flex flex-wrap gap-1 mt-2" id="quick-amounts"></div>
                </div>

                <div>
                    <label class="field-label" for="payment_method">Method</label>
                    <select id="payment_method" name="payment_method" class="input">
                        @foreach ($methods as $key => $text)
                            <option value="{{ $key }}" @selected(old('payment_method', 'cash') === $key)>{{ $text }}</option>
                        @endforeach
                    </select>
                    <div class="mt-2 hidden" id="ref-box">
                        <input name="payment_ref" id="payment_ref" value="{{ old('payment_ref') }}" maxlength="60" class="input" placeholder="Check / transfer / wallet no.">
                    </div>
                </div>

                <x-field label="Remarks / particulars" name="remarks" class="md:col-span-2" />

                {{-- Live preview --}}
                <div class="md:col-span-2 bg-surface-container-low rounded-lg p-space-md text-sm" id="preview">
                    <div class="flex justify-between"><span class="text-on-surface-variant" id="preview-label">Balance before</span><strong id="preview-before">—</strong></div>
                    <div class="flex justify-between"><span class="text-on-surface-variant" id="preview-change-label">This transaction</span><strong id="preview-change">—</strong></div>
                    <div class="flex justify-between border-t border-outline-variant mt-1 pt-1"><span class="font-semibold" id="preview-after-label">Balance after</span><strong id="preview-after" class="text-primary">—</strong></div>
                    <p class="field-error hidden mt-1" id="preview-warning"></p>
                </div>

                <div class="md:col-span-2 flex items-center gap-3">
                    <button class="btn btn-primary" id="submit-btn">Post transaction</button>
                    <span class="text-[11px] text-outline">A printable slip opens after posting.</span>
                </div>
            </form>
        @endif
    </x-card>

    {{-- Account panel --}}
    <x-card title="Account" class="lg:col-span-2" id="account-card">
        <div class="p-space-md pt-0 text-sm" id="account-panel">
            <p class="text-on-surface-variant">Choose a member to see their savings, shares and loans.</p>
        </div>
    </x-card>
</div>

<x-card title="Recent Teller Transactions" subtitle="Deposits, withdrawals and loan payments">
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Reference</th><th>Date &amp; time</th><th>Member</th><th>Type</th><th class="num">Amount</th><th class="num">Balance after</th><th>Teller</th><th class="no-print"></th></tr></thead>
            <tbody>
                @forelse ($recent as $t)
                    <tr>
                        <td class="font-mono text-[12px] font-semibold text-primary">{{ $t->reference_no }}</td>
                        <td>{{ $t->posted_at->format('M d, Y h:i A') }}</td>
                        <td>{{ $t->member?->full_name }}</td>
                        <td><span class="inline-flex px-2 py-0.5 rounded text-[11px] font-semibold {{ $t->badge() }}">{{ $t->label() }}</span></td>
                        <td class="num font-bold {{ $t->direction === 'in' ? 'text-secondary' : '' }} {{ $t->status === 'voided' ? 'line-through opacity-60' : '' }}">{{ $t->direction === 'in' ? '+' : '-' }}@peso($t->amount)</td>
                        <td class="num">{{ $t->balance_after !== null ? '₱' . number_format($t->balance_after, 2) : '—' }}</td>
                        <td>{{ $t->user?->name }}</td>
                        <td class="no-print"><a href="{{ route('transactions.receipt', $t) }}" class="btn btn-secondary btn-sm">Slip</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-on-surface-variant py-6">Nothing yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-card>

<x-card title="Savings Accounts" subtitle="Balance of every active member">
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Member</th><th class="num">Savings balance</th><th class="no-print"></th></tr></thead>
            <tbody>
                @foreach ($members as $m)
                    <tr>
                        <td><a href="{{ route('members.show', $m) }}" class="font-medium text-primary">{{ $m->last_name }}, {{ $m->first_name }}</a><div class="text-[11px] text-outline">{{ $m->member_no }}</div></td>
                        <td class="num font-semibold">@peso($m->savings)</td>
                        <td class="no-print text-right"><a href="{{ route('savings.statement', $m) }}" class="btn btn-secondary btn-sm">Statement</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-card>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/teller.js') }}"></script>
@endpush
