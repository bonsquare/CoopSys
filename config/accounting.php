<?php

/*
|--------------------------------------------------------------------------
| Accounting report settings
|--------------------------------------------------------------------------
| Everything here is generic. Change the names and rates to suit your organization.
*/

return [
    // Chart of accounts given to every new organization: [code, name, type, used for automatic postings]
    'default_chart' => [
        ['1010', 'Cash on Hand', 'asset', true],
        ['1011', 'Cash in Bank', 'asset', true],
        ['1020', 'Loans Receivable', 'asset', true],
        ['1030', 'Other Receivables', 'asset', true],
        ['1040', 'Property and Equipment', 'asset', false],
        ['2010', "Members' Savings Deposits", 'liability', true],
        ['2020', 'Insurance Payable', 'liability', true],
        ['2030', 'Accounts Payable', 'liability', false],
        ['3010', 'Share Capital - Paid-up', 'equity', true],
        ['3020', 'Retained Surplus', 'equity', false],
        ['4010', 'Interest Income on Loans', 'income', true],
        ['4020', 'Fees and Other Income', 'income', true],
        ['4030', 'Penalty Income', 'income', true],
        ['5010', 'Operating Expenses', 'expense', true],
        ['5020', 'Salaries and Benefits', 'expense', false],
        ['5030', 'Rent and Utilities', 'expense', false],
        ['5040', 'Bank Charges', 'expense', false],
    ],

    // Sections used on the financial statements. Each account has a "report group" (editable in the
    // Chart of Accounts). These are the defaults for the standard accounts.
    'groups' => [
        '1010' => 'Cash and Bank', '1011' => 'Cash and Bank',
        '1020' => 'Loans and Receivables', '1030' => 'Loans and Receivables',
        '1040' => 'Property and Equipment',
        '2010' => 'Member Deposits',
        '2020' => 'Payables', '2030' => 'Payables',
        '3010' => 'Share Capital', '3020' => 'Reserves and Surplus',
        '4010' => 'Interest Income', '4020' => 'Fees and Other Income', '4030' => 'Fees and Other Income',
        '5010' => 'Operating Expenses', '5020' => 'Personnel Expenses', '5030' => 'Operating Expenses', '5040' => 'Financial Expenses',
    ],

    // Group used when an account has none, by account type
    'default_group' => [
        'asset' => 'Other Assets', 'liability' => 'Other Liabilities', 'equity' => 'Other Equity',
        'income' => 'Other Income', 'expense' => 'Other Expenses',
    ],

    // Cash flow: which section an account falls in when it is the other side of a cash entry.
    // Anything not listed: income and expense = operating; assets = investing; savings and equity = financing; other liabilities = operating.
    'cash_flow' => [
        '1030' => 'operating',   // other receivables
        '2010' => 'financing',   // member deposits
        '2020' => 'operating',   // insurance payable
        '2030' => 'operating',   // accounts payable
    ],

    // Aging of the loan portfolio: days past due, and the suggested allowance for losses on each bucket (%)
    'aging' => [
        ['key' => 'current', 'label' => 'Current (not past due)', 'from' => 0, 'to' => 0, 'rate' => 1],
        ['key' => '1_30', 'label' => '1 to 30 days', 'from' => 1, 'to' => 30, 'rate' => 5],
        ['key' => '31_60', 'label' => '31 to 60 days', 'from' => 31, 'to' => 60, 'rate' => 10],
        ['key' => '61_90', 'label' => '61 to 90 days', 'from' => 61, 'to' => 90, 'rate' => 25],
        ['key' => '91_180', 'label' => '91 to 180 days', 'from' => 91, 'to' => 180, 'rate' => 50],
        ['key' => 'over_180', 'label' => 'Over 180 days', 'from' => 181, 'to' => null, 'rate' => 100],
    ],
];
