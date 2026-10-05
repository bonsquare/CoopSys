@extends('layouts.app')

@section('title', 'New Journal Entry')

@php
    // Lines typed before an error, or two empty lines to start with
    $lines = old('lines', [['account_id' => '', 'debit' => '', 'credit' => '', 'memo' => ''], ['account_id' => '', 'debit' => '', 'credit' => '', 'memo' => '']]);
@endphp

@section('content')
<x-page-header title="New Journal Entry" subtitle="Total debits must equal total credits. A posted entry cannot be edited, only reversed.">
    <a href="{{ route('accounting.journal.index') }}" class="btn btn-secondary">Cancel</a>
</x-page-header>

@include('accounting._nav')

<form method="POST" action="{{ route('accounting.journal.store') }}" id="journal-form" class="flex flex-col gap-gutter max-w-5xl">
    @csrf

    <x-card>
        <div class="p-space-md grid grid-cols-1 md:grid-cols-3 gap-4">
            <x-field label="Entry date" name="entry_date" type="date" :value="now()->toDateString()" max="{{ now()->toDateString() }}" required />
            <x-field label="Reference / voucher no." name="reference" hint="Optional, e.g. a bank slip or invoice number." />
            <x-field label="Description" name="description" required class="md:col-span-3" />
        </div>
    </x-card>

    <x-card title="Lines">
        <div class="overflow-x-auto">
            <table class="data-table" id="lines-table">
                <thead><tr><th style="min-width:260px">Account</th><th style="min-width:180px">Memo</th><th class="num" style="min-width:130px">Debit</th><th class="num" style="min-width:130px">Credit</th><th></th></tr></thead>
                <tbody id="lines-body">
                    @foreach ($lines as $i => $line)
                        @include('accounting.journal._line', ['i' => $i, 'line' => $line, 'accounts' => $accounts])
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="bg-surface-container-low font-bold">
                        <td colspan="2" class="text-right">Totals</td>
                        <td class="num" id="total-debit">₱0.00</td>
                        <td class="num" id="total-credit">₱0.00</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="p-space-md flex flex-wrap items-center gap-3">
            <button type="button" class="btn btn-secondary" id="add-line"><span class="material-symbols-outlined text-[18px]">add</span> Add line</button>
            <span id="balance-note" class="text-sm font-semibold"></span>
        </div>
    </x-card>

    <div class="flex gap-2">
        <button class="btn btn-primary" id="post-btn" disabled>Post entry</button>
        <a href="{{ route('accounting.journal.index') }}" class="btn btn-secondary">Cancel</a>
    </div>
</form>

<template id="line-template">
    @include('accounting.journal._line', ['i' => '__INDEX__', 'line' => ['account_id' => '', 'debit' => '', 'credit' => '', 'memo' => ''], 'accounts' => $accounts])
</template>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/journal-form.js') }}"></script>
@endpush
