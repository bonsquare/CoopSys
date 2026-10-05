@extends('layouts.app')

@section('title', 'Accounting Reports')

@php
    $groups = [
        'Financial statements' => [
            ['Income Statement', 'accounting.income', 'Income and expenses for any period, side by side with the period before or the same period last year.', 'trending_up'],
            ['Balance Sheet', 'accounting.balance', 'Assets, liabilities and equity on any date, compared with last month-end or last year-end.', 'balance'],
            ['Cash Flow Statement', 'accounting.cashflow', 'Where the cash came from and where it went: operating, investing and financing.', 'water_drop'],
            ['Changes in Equity', 'accounting.equity', 'How share capital, reserves and surplus moved during the period.', 'stacked_line_chart'],
            ['Trial Balance', 'accounting.trial', 'Every account\'s balance on a date, debits against credits.', 'view_list'],
        ],
        'Books of account' => [
            ['General Ledger', 'accounting.ledger', 'One account\'s history with a running balance.', 'menu_book'],
            ['General Journal', 'accounting.daybook', 'Every entry with its debit and credit lines, for a period. Printable.', 'receipt_long'],
            ['Cash Book', 'accounting.cashbook', 'All cash and bank receipts and payments in order, with a running balance.', 'payments'],
            ['Subsidiary Schedules', 'accounting.schedules', 'The member and loan accounts behind loans, savings and share capital, tied to the ledger.', 'fact_check'],
        ],
        'Management' => [
            ['Financial Highlights', 'accounting.highlights', 'Key ratios and the 12-month trend of income, expenses and surplus.', 'monitoring'],
            ['Loan Portfolio Aging', 'accounting.aging', 'Loans by days past due, portfolio at risk, and a suggested allowance for losses.', 'hourglass_top'],
            ['Year-end Closing', 'accounting.closing', 'Close a year into retained surplus and lock the books.', 'lock'],
        ],
    ];
@endphp

@section('content')
<x-page-header title="Accounting Reports" subtitle="Every report can be printed and exported to Excel." />

@include('accounting._nav')

@foreach ($groups as $title => $items)
    <div>
        <h2 class="font-headline-sm text-headline-sm mb-2">{{ $title }}</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-gutter">
            @foreach ($items as [$label, $routeName, $text, $icon])
                <a href="{{ route($routeName) }}" class="bg-surface-container-lowest rounded-xl shadow-sm p-space-md flex gap-3 hover:shadow-md transition-shadow">
                    <span class="material-symbols-outlined text-primary text-[28px]">{{ $icon }}</span>
                    <div>
                        <div class="font-semibold text-on-surface">{{ $label }}</div>
                        <div class="text-[13px] text-on-surface-variant">{{ $text }}</div>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
@endforeach
@endsection
