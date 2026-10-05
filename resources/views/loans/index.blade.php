@extends('layouts.app')

@section('title', 'Loans')

@section('content')
<x-page-header title="Loan Management" subtitle="Applications, approvals, releases and balances.">
    @allowed('loans', 'manage')
        <a href="{{ route('loans.create') }}" class="btn btn-primary"><span class="material-symbols-outlined text-[18px]">add</span> New Loan Application</a>
    @endallowed
</x-page-header>

<x-card>
    <form method="GET" class="p-space-md flex flex-wrap items-end gap-space-sm no-print">
        <div class="min-w-[260px]">
            <label class="field-label" for="q">Search</label>
            <input id="q" name="q" value="{{ $search }}" placeholder="Loan no. or member" class="input">
        </div>
        <x-select label="Status" name="status" :value="$status" placeholder="All"
                  :options="['pending' => 'Pending', 'approved' => 'Approved', 'released' => 'Released (active)', 'pastdue' => 'Past due', 'paid' => 'Paid', 'rejected' => 'Rejected']" />
        <button class="btn btn-secondary">Filter</button>
        @if ($search || $status) <a href="{{ route('loans.index') }}" class="btn btn-secondary">Clear</a> @endif
    </form>

    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Loan No.</th><th>Member</th><th class="num">Principal</th><th>Term</th><th class="num">Balance</th><th>Maturity</th><th>Status</th><th class="no-print"></th></tr></thead>
            <tbody>
                @forelse ($loans as $loan)
                    <tr>
                        <td class="font-mono text-[12px] font-semibold text-primary">{{ $loan->loan_no }}</td>
                        <td>{{ $loan->member->full_name }}<div class="text-[11px] text-outline">{{ $loan->member->member_no }}</div></td>
                        <td class="num">@peso($loan->principal)</td>
                        <td>{{ $loan->term_value ?? $loan->term_months }} {{ strtolower(\App\Models\LoanProduct::label('term_units', $loan->term_unit)) }}</td>
                        <td class="num font-semibold">@peso($loan->balance)</td>
                        <td>
                            {{ $loan->maturity_date?->format('M d, Y') ?? '—' }}
                            @if ($loan->isPastDue()) <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-error-container text-on-error-container">PAST DUE</span> @endif
                        </td>
                        <td><span class="inline-flex px-2 py-0.5 rounded text-[11px] font-semibold {{ \App\Models\Loan::statusColor($loan->status) }}">{{ ucfirst($loan->status) }}</span></td>
                        <td class="no-print"><a href="{{ route('loans.show', $loan) }}" class="btn btn-secondary btn-sm">View</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-on-surface-variant py-8">No loans found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-space-md no-print">{{ $loans->links() }}</div>
</x-card>
@endsection
