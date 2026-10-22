@extends('layouts.app')

@php
    $label = fn (string $list, $key) => \App\Models\LoanProduct::label($list, $key);
    $yn = fn ($v) => $v ? 'Yes' : 'No';
    $docs = collect($product->required_documents ?? [])->map(fn ($k) => $label('documents', $k))->implode(', ');
@endphp

@section('title', $product->name)

@section('content')
<x-page-header :title="$product->name" :subtitle="$product->code . ($product->category ? ' · ' . $product->category : '') . ' · ' . ($product->is_active ? 'Active' : 'Inactive')">
    <a href="{{ route('loan-products.index') }}" class="btn btn-secondary">Back</a>
    <button type="button" onclick="window.print()" class="btn btn-secondary"><span class="material-symbols-outlined text-[18px]">print</span> Print product terms</button>
    @allowed('loan_products', 'manage')
        <a href="{{ route('loan-products.edit', $product) }}" class="btn btn-secondary">Edit</a>
        <form method="POST" action="{{ route('loan-products.toggle', $product) }}" onsubmit="return confirm('Change this product\'s status?')">
            @csrf <button class="btn {{ $product->is_active ? 'btn-danger' : 'btn-success' }}">{{ $product->is_active ? 'Deactivate' : 'Activate' }}</button>
        </form>
    @endallowed
</x-page-header>

@if ($product->description)
    <p class="text-on-surface-variant -mt-3">{{ $product->description }}</p>
@endif

<div class="grid grid-cols-2 lg:grid-cols-4 gap-gutter">
    <x-card class="p-space-md"><div class="field-label">Interest</div><div class="font-headline-sm text-headline-sm font-bold">{{ $product->rateText() }}</div><div class="text-xs text-outline">{{ $label('interest_types', $product->interest_type) }}</div></x-card>
    <x-card class="p-space-md col-span-2 lg:col-span-1"><div class="field-label">Amount</div><div class="font-headline-sm text-headline-sm font-bold">@peso($product->min_amount) – @peso($product->max_amount)</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Term</div><div class="font-headline-sm text-headline-sm font-bold">{{ $product->termRange() }}</div><div class="text-xs text-outline">Paid {{ strtolower($label('frequencies', $product->payment_frequency)) }}</div></x-card>
    <x-card class="p-space-md"><div class="field-label">Loans using this product</div><div class="font-headline-sm text-headline-sm font-bold">{{ $product->loans_count }}</div></x-card>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-gutter items-start">
    <x-card title="Amount and Term">
        <dl class="p-space-md pt-0 grid grid-cols-2 gap-y-2 text-sm">
            <dt class="text-on-surface-variant">Minimum amount</dt><dd>@peso($product->min_amount)</dd>
            <dt class="text-on-surface-variant">Maximum amount</dt><dd>@peso($product->max_amount)</dd>
            <dt class="text-on-surface-variant">Term</dt><dd>{{ $product->termRange() }}</dd>
            <dt class="text-on-surface-variant">Payment frequency</dt><dd>{{ $label('frequencies', $product->payment_frequency) }}</dd>
        </dl>
    </x-card>

    <x-card title="Interest and Payment Schedule">
        <dl class="p-space-md pt-0 grid grid-cols-2 gap-y-2 text-sm">
            <dt class="text-on-surface-variant">Computation type</dt><dd>{{ $label('interest_types', $product->interest_type) }}</dd>
            <dt class="text-on-surface-variant">Interest rate</dt><dd>{{ $product->rateText() }}</dd>
            <dt class="text-on-surface-variant">Amortization</dt><dd>{{ $label('amortization_methods', $product->amortization_method) }}</dd>
            <dt class="text-on-surface-variant">Auto-generate schedule</dt><dd>{{ $yn($product->auto_schedule) }}</dd>
        </dl>
    </x-card>

    <x-card title="Penalties">
        <dl class="p-space-md pt-0 grid grid-cols-2 gap-y-2 text-sm">
            <dt class="text-on-surface-variant">Penalty</dt>
            <dd>
                @if ($product->penalty_type === 'none') None
                @else {{ $product->penalty_type === 'percentage' ? (float) $product->penalty_value . '%' : '₱' . number_format($product->penalty_value, 2) }} {{ strtolower($label('penalty_bases', $product->penalty_basis)) }}
                @endif
            </dd>
            <dt class="text-on-surface-variant">Grace period</dt><dd>{{ $product->grace_days }} days</dd>
        </dl>
    </x-card>

    <x-card title="Requirements">
        <dl class="p-space-md pt-0 grid grid-cols-2 gap-y-2 text-sm">
            <dt class="text-on-surface-variant">Minimum savings / deposit</dt><dd>@peso($product->min_savings)</dd>
            <dt class="text-on-surface-variant">Minimum share / capital</dt><dd>@peso($product->min_shares)</dd>
            <dt class="text-on-surface-variant">Guarantor / co-maker</dt><dd>{{ $product->guarantor_required ? 'Required (' . $product->guarantors_required . ')' : 'Not required' }}</dd>
            <dt class="text-on-surface-variant">Documents</dt><dd>{{ $docs ?: 'None' }}</dd>
        </dl>
    </x-card>

    <x-card title="Approval and Release">
        <dl class="p-space-md pt-0 grid grid-cols-2 gap-y-2 text-sm">
            <dt class="text-on-surface-variant">Approval level</dt><dd>{{ $label('approval_levels', $product->approval_level) }}</dd>
            <dt class="text-on-surface-variant">Release method</dt><dd>{{ $label('release_methods', $product->release_method) }}</dd>
        </dl>
    </x-card>

    <x-card title="Other Settings">
        <dl class="p-space-md pt-0 grid grid-cols-2 gap-y-2 text-sm">
            <dt class="text-on-surface-variant">Early payment</dt><dd>{{ $yn($product->allow_early_payment) }}</dd>
            <dt class="text-on-surface-variant">Early payment penalty</dt><dd>{{ $product->early_payment_penalty ? (float) $product->early_payment_penalty_rate . '%' : 'No' }}</dd>
            <dt class="text-on-surface-variant">Renewal</dt><dd>{{ $yn($product->allow_renewal) }}</dd>
            <dt class="text-on-surface-variant">Restructuring</dt><dd>{{ $yn($product->allow_restructuring) }}</dd>
        </dl>
    </x-card>
</div>

<x-card title="Deductions" subtitle="Charges applied to every loan of this product">
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr><th>Name</th><th>Type</th><th class="num">Value</th><th>Basis</th><th>Timing</th><th>Required</th><th>Editable</th><th>Status</th></tr></thead>
            <tbody>
                @forelse ($product->deductions as $d)
                    <tr class="{{ $d->is_active ? '' : 'opacity-50' }}">
                        <td class="font-medium">{{ $d->name }}</td>
                        <td>{{ $label('deduction_types', $d->type) }}</td>
                        <td class="num">{{ $d->type === 'percentage' ? (float) $d->value . '%' : ($d->type === 'none' ? '—' : '₱' . number_format($d->value, 2)) }}</td>
                        <td>{{ $label('deduction_bases', $d->basis) }}</td>
                        <td>{{ $label('deduction_timings', $d->timing) }}</td>
                        <td>{{ $yn($d->is_required) }}</td>
                        <td>{{ $yn($d->is_editable) }}</td>
                        <td>{{ $d->is_active ? 'Active' : 'Inactive' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-on-surface-variant py-6">This product has no deductions.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-card>

{{-- Sample computation tool --}}
<x-card title="Sample Computation" subtitle="Enter a loan amount, term and payment frequency to see how this product works out.">
    <form method="GET" class="p-space-md pt-0 grid grid-cols-2 md:grid-cols-4 gap-3 items-end no-print">
        <x-field label="Loan amount (₱)" name="amount" type="number" step="0.01" min="1" :value="$amount" required />
        <x-field :label="'Loan term (' . strtolower($label('term_units', $product->term_unit)) . ')'" name="term" type="number" step="1" min="1" :value="$term" required />
        <x-select label="Payment frequency" name="frequency" :value="$frequency" :options="config('loan_products.frequencies')" required />
        <button class="btn btn-primary justify-center">Compute</button>
    </form>

    @foreach ($warnings as $w)
        <div class="mx-space-md mb-2 bg-error-container text-on-error-container px-space-md py-space-sm rounded-lg text-sm">{{ $w }}</div>
    @endforeach

    <div class="p-space-md pt-0 grid grid-cols-1 lg:grid-cols-2 gap-gutter items-start">
        <div>
            <table class="data-table">
                <tbody>
                    <tr><td>Approved Loan Amount</td><td class="num font-semibold">@peso($calc['approved_amount'])</td></tr>
                    <tr><td class="text-on-surface-variant pl-6">Principal amount</td><td class="num">@peso($calc['approved_amount'])</td></tr>
                    @foreach ($calc['deductions'] as $d)
                        <tr>
                            <td class="text-on-surface-variant pl-6">{{ $d['name'] }} <span class="text-xs">({{ strtolower($label('deduction_timings', $d['timing'])) }})</span></td>
                            <td class="num">@peso($d['amount'])</td>
                        </tr>
                    @endforeach
                    <tr><td>Less: Total Deductions</td><td class="num font-semibold">(@peso($calc['total_deductions']))</td></tr>
                    <tr class="bg-surface-container-low"><td class="font-bold">Net Proceeds</td><td class="num font-bold">@peso($calc['net_proceeds'])</td></tr>
                    <tr><td>Add: Interest</td><td class="num">@peso($calc['interest'])</td></tr>
                    <tr><td>Add: Fees Added to Payable</td><td class="num">@peso($calc['fees_payable'])</td></tr>
                    <tr class="bg-surface-container-low"><td class="font-bold">Total Amount Payable</td><td class="num font-bold">@peso($calc['total_payable'])</td></tr>
                    <tr>
                        <td>Payment Amount Per Schedule<div class="text-xs text-outline">{{ $calc['payments'] }} {{ strtolower($label('frequencies', $calc['frequency'])) }} payment(s)</div></td>
                        <td class="num font-bold text-primary">
                            @peso($calc['payment_amount'])
                            @if ($calc['payments_vary'])<div class="text-xs text-outline font-normal">First payment @peso($calc['first_payment'])</div>@endif
                        </td>
                    </tr>
                    <tr><td>Ending Loan Balance<div class="text-xs text-outline">after the last payment</div></td><td class="num">@peso(end($calc['rows'])['balance'])</td></tr>
                </tbody>
            </table>
            @if ($calc['custom_schedule'])
                <p class="field-hint">This product uses a custom schedule. The figures above are an even-payment estimate.</p>
            @endif
        </div>

        <div class="overflow-x-auto max-h-[28rem]">
            <table class="data-table">
                <thead><tr><th>#</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Fees</th><th class="num">Payment</th><th class="num">Balance</th></tr></thead>
                <tbody>
                    @foreach ($calc['rows'] as $row)
                        <tr>
                            <td>{{ $row['no'] }}</td>
                            <td class="num">@peso($row['principal'])</td>
                            <td class="num">@peso($row['interest'])</td>
                            <td class="num">@peso($row['fees'])</td>
                            <td class="num font-semibold">@peso($row['payment'])</td>
                            <td class="num">@peso($row['balance'])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-card>
@endsection
