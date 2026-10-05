@extends('layouts.app')

@php
    $editing = $product->exists;
    $yesNo = ['1' => 'Yes', '0' => 'No'];
    $cfg = fn (string $list) => config("loan_products.$list");

    // Deduction rows: what the user just typed (after an error), or what is saved
    $rows = old('deductions', $deductions->map(fn ($d) => $d->only(['name', 'type', 'value', 'basis', 'timing', 'is_required', 'is_editable', 'is_active']))->all());
    $selectedDocs = old('required_documents', $product->required_documents ?? []);
@endphp

@section('title', $editing ? 'Edit Loan Product' : 'Add Loan Product')

@section('content')
<x-page-header :title="$editing ? 'Edit Loan Product' : 'Add Loan Product'" subtitle="Changes only affect new loan applications. Loans already approved keep their terms.">
    <a href="{{ $editing ? route('loan-products.show', $product) : route('loan-products.index') }}" class="btn btn-secondary">Cancel</a>
</x-page-header>

<form method="POST" action="{{ $editing ? route('loan-products.update', $product) : route('loan-products.store') }}" class="flex flex-col gap-gutter max-w-5xl" id="product-form">
    @csrf
    @if ($editing) @method('PUT') @endif

    {{-- A. Basic information --}}
    <x-card title="A. Basic Information">
        <div class="p-space-md pt-0 grid grid-cols-1 md:grid-cols-2 gap-4">
            <x-field label="Product name" name="name" :value="$product->name" required />
            <x-field label="Product code" name="code" :value="$product->code" required hint="Short and unique, e.g. LP-001" />
            <div>
                <label class="field-label" for="category">Product category</label>
                <input id="category" name="category" value="{{ old('category', $product->category) }}" list="category-list" class="input" placeholder="Type or pick a category">
                <datalist id="category-list">@foreach ($cfg('categories') as $c) <option value="{{ $c }}"> @endforeach</datalist>
                @error('category') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <x-select label="Status" name="is_active" :value="(int) $product->is_active" :options="['1' => 'Active', '0' => 'Inactive']" required />
            <x-field label="Description" name="description" :value="$product->description" class="md:col-span-2" />
        </div>
    </x-card>

    {{-- B. Amount and term --}}
    <x-card title="B. Amount and Term">
        <div class="p-space-md pt-0 grid grid-cols-2 md:grid-cols-3 gap-4">
            <x-field label="Minimum loan amount" name="min_amount" type="number" step="0.01" min="0" :value="$product->min_amount" required />
            <x-field label="Maximum loan amount" name="max_amount" type="number" step="0.01" min="0" :value="$product->max_amount" required />
            <div class="hidden md:block"></div>
            <x-field label="Minimum term" name="min_term" type="number" step="1" min="1" :value="$product->min_term" required />
            <x-field label="Maximum term" name="max_term" type="number" step="1" min="1" :value="$product->max_term" required />
            <x-select label="Term unit" name="term_unit" :value="$product->term_unit" :options="$cfg('term_units')" required />
            <x-select label="Payment frequency" name="payment_frequency" :value="$product->payment_frequency" :options="$cfg('frequencies')" required />
        </div>
    </x-card>

    {{-- C. Interest and payment schedule --}}
    <x-card title="C. Interest and Payment Schedule">
        <div class="p-space-md pt-0 grid grid-cols-2 md:grid-cols-3 gap-4">
            <x-select label="Interest computation type" name="interest_type" :value="$product->interest_type" :options="$cfg('interest_types')" required
                      hint="Flat, Add-on and Simple charge interest on the original amount. Diminishing charges it on the balance still owed." class="col-span-2 md:col-span-3" />
            <x-field label="Interest rate (%)" name="interest_rate" type="number" step="0.01" min="0" :value="$product->interest_rate ?? 0" />
            <x-select label="Interest basis" name="interest_basis" :value="$product->interest_basis" :options="$cfg('interest_bases')" required />
            <x-select label="Amortization method" name="amortization_method" :value="$product->amortization_method" :options="$cfg('amortization_methods')" required />
            <x-select label="Auto-generate payment schedule" name="auto_schedule" :value="(int) $product->auto_schedule" :options="$yesNo" required />
        </div>
    </x-card>

    {{-- D. Deductions --}}
    <x-card title="D. Deductions" subtitle="Charges taken from, or added to, every loan of this product. Add as many as you need.">
        <div class="p-space-md pt-0 flex flex-col gap-3">
            <datalist id="deduction-names">@foreach ($cfg('deduction_names') as $n) <option value="{{ $n }}"> @endforeach</datalist>
            <div id="deduction-list" class="flex flex-col gap-3">
                @foreach ($rows as $i => $d)
                    @include('loan-products._deduction-row', ['i' => $i, 'd' => $d])
                @endforeach
            </div>
            <p id="no-deductions" class="text-sm text-on-surface-variant {{ count($rows) ? 'hidden' : '' }}">No deductions. Click "Add deduction" if this product has fees.</p>
            <div><button type="button" class="btn btn-secondary" id="add-deduction"><span class="material-symbols-outlined text-[18px]">add</span> Add deduction</button></div>
            <p class="field-hint">Type "None" disables the value. "Deduct upon release" lowers the cash the borrower receives. "Add to payable" and "Deduct from first payment" are paid back with the loan.</p>
        </div>
    </x-card>

    {{-- E. Penalties --}}
    <x-card title="E. Penalties">
        <div class="p-space-md pt-0 grid grid-cols-2 md:grid-cols-4 gap-4">
            <x-select label="Penalty type" name="penalty_type" :value="$product->penalty_type" :options="$cfg('penalty_types')" required />
            <x-field label="Penalty value" name="penalty_value" type="number" step="0.01" min="0" :value="$product->penalty_value ?? 0" />
            <x-select label="Penalty basis" name="penalty_basis" :value="$product->penalty_basis" :options="$cfg('penalty_bases')" required />
            <x-field label="Grace period (days)" name="grace_days" type="number" step="1" min="0" max="365" :value="$product->grace_days ?? 0" />
        </div>
    </x-card>

    {{-- F. Requirements --}}
    <x-card title="F. Requirements">
        <div class="p-space-md pt-0 grid grid-cols-2 md:grid-cols-4 gap-4">
            <x-field label="Required minimum savings / deposit" name="min_savings" type="number" step="0.01" min="0" :value="$product->min_savings ?? 0" class="col-span-2" />
            <x-field label="Required minimum share / capital" name="min_shares" type="number" step="0.01" min="0" :value="$product->min_shares ?? 0" class="col-span-2" />
            <x-select label="Guarantor / co-maker required" name="guarantor_required" :value="(int) $product->guarantor_required" :options="$yesNo" required class="col-span-2" />
            <x-field label="Number of guarantors / co-makers" name="guarantors_required" type="number" step="1" min="0" max="20" :value="$product->guarantors_required ?? 0" class="col-span-2" />
            <div class="col-span-2 md:col-span-4">
                <div class="field-label">Required documents</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2">
                    @foreach ($cfg('documents') as $key => $text)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="required_documents[]" value="{{ $key }}" @checked(in_array($key, $selectedDocs))> {{ $text }}
                        </label>
                    @endforeach
                </div>
            </div>
        </div>
    </x-card>

    {{-- G. Approval and release --}}
    <x-card title="G. Approval and Release">
        <div class="p-space-md pt-0 grid grid-cols-2 gap-4">
            <x-select label="Approval level" name="approval_level" :value="$product->approval_level" :options="$cfg('approval_levels')" required />
            <x-select label="Release method" name="release_method" :value="$product->release_method" :options="$cfg('release_methods')" required />
        </div>
    </x-card>

    {{-- H. Other settings --}}
    <x-card title="H. Other Settings">
        <div class="p-space-md pt-0 grid grid-cols-2 md:grid-cols-3 gap-4">
            <x-select label="Allow early payment" name="allow_early_payment" :value="(int) $product->allow_early_payment" :options="$yesNo" required />
            <x-select label="Early payment penalty" name="early_payment_penalty" :value="(int) $product->early_payment_penalty" :options="$yesNo" required />
            <x-field label="Early payment penalty (% of amount paid early)" name="early_payment_penalty_rate" type="number" step="0.01" min="0" max="100" :value="$product->early_payment_penalty_rate ?? 0" />
            <x-select label="Allow renewal" name="allow_renewal" :value="(int) $product->allow_renewal" :options="$yesNo" required />
            <x-select label="Allow restructuring" name="allow_restructuring" :value="(int) $product->allow_restructuring" :options="$yesNo" required />
        </div>
    </x-card>

    <div class="flex gap-2">
        <button class="btn btn-primary">{{ $editing ? 'Save changes' : 'Add loan product' }}</button>
        <a href="{{ $editing ? route('loan-products.show', $product) : route('loan-products.index') }}" class="btn btn-secondary">Cancel</a>
    </div>
</form>

{{-- Blank deduction row, copied by the "Add deduction" button --}}
<template id="deduction-template">
    @include('loan-products._deduction-row', ['i' => '__INDEX__', 'd' => []])
</template>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/loan-product-form.js') }}"></script>
@endpush
