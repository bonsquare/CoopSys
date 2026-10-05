@extends('layouts.app')

@section('title', $title)

@section('content')
<x-page-header :title="$title" :subtitle="$subtitle" />

<x-card class="p-space-md max-w-sm">
    <div class="field-label">{{ $mode === 'receipts' ? 'Received' : 'Paid out' }} today</div>
    <div class="font-headline-md text-headline-md font-bold {{ $mode === 'receipts' ? 'text-secondary' : '' }}">@peso($total)</div>
</x-card>

@allowed($mode, 'manage')
<x-card title="New Entry" class="no-print">
    <form method="POST" action="{{ $storeRoute }}" class="p-space-md pt-0 grid grid-cols-1 md:grid-cols-6 gap-4 items-start" id="cash-form">
        @csrf
        <x-select label="Type" name="type" :options="$formTypes" required class="md:col-span-2" id="cash-type" />
        <x-select label="Member" name="member_id" :options="$options" placeholder="— none —" class="md:col-span-2" id="cash-member" />
        <div class="md:col-span-2 {{ old('type') === 'loan_payment' || ($mode === 'receipts' && ! old('type')) ? '' : 'hidden' }}" id="loan-box">
            <label class="field-label" for="loan_id">Loan</label>
            <select name="loan_id" id="loan_id" class="input"><option value="">Choose member first</option></select>
            @error('loan_id') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <x-field label="Amount (₱)" name="amount" type="number" step="0.01" min="0.01" required class="md:col-span-2" />
        <x-field label="Remarks / particulars" name="remarks" class="md:col-span-3" />
        <div><button class="btn btn-primary">Post entry</button></div>
        <p class="md:col-span-6 field-hint">A member is required for every type except Other Receipt and Expense.</p>
    </form>
</x-card>
@endallowed

<x-card title="Recent Entries">
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Reference</th><th>Date</th><th>Member</th><th>Type</th><th>Remarks</th><th class="num">Amount</th><th>By</th><th>Status</th></tr></thead>
            <tbody>
                @forelse ($rows as $t)
                    <tr>
                        <td class="font-mono text-[12px] font-semibold text-primary"><a href="{{ route('transactions.show', $t) }}">{{ $t->reference_no }}</a></td>
                        <td>{{ $t->posted_at->format('M d, Y h:i A') }}</td>
                        <td>{{ $t->member?->full_name ?? '—' }}</td>
                        <td><span class="inline-flex px-2 py-0.5 rounded text-[11px] font-semibold {{ $t->badge() }}">{{ $t->label() }}</span></td>
                        <td class="max-w-[220px] truncate">{{ $t->remarks }}</td>
                        <td class="num font-bold {{ $t->direction === 'in' ? 'text-secondary' : '' }} {{ $t->status === 'voided' ? 'line-through opacity-60' : '' }}">@peso($t->amount)</td>
                        <td>{{ $t->user?->name }}</td>
                        <td>{{ ucfirst($t->status) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-on-surface-variant py-8">Nothing recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-space-md no-print">{{ $rows->links() }}</div>
</x-card>
@endsection

@push('scripts')
<script>
    // Show the loan dropdown only for "Loan Repayment", and fill it with the member's released loans
    (function () {
        const type = document.getElementById('cash-type');
        const member = document.getElementById('cash-member');
        const box = document.getElementById('loan-box');
        const loan = document.getElementById('loan_id');
        if (!type || !member || !box || !loan) return;

        async function loadLoans() {
            const isLoan = type.value === 'loan_payment';
            box.classList.toggle('hidden', !isLoan);
            if (!isLoan) { loan.value = ''; return; }
            loan.innerHTML = '<option value="">Choose member first</option>';
            if (!member.value) return;
            const res = await fetch('{{ url('/members') }}/' + member.value + '/loans-json', { headers: { 'Accept': 'application/json' } });
            const loans = await res.json();
            loan.innerHTML = loans.length ? '' : '<option value="">This member has no active loan</option>';
            loans.forEach((l) => loan.insertAdjacentHTML('beforeend', '<option value="' + l.id + '">' + l.label + '</option>'));
        }
        type.addEventListener('change', loadLoans);
        member.addEventListener('change', loadLoans);
        loadLoans();
    })();
</script>
@endpush
