<?php

/*
|--------------------------------------------------------------------------
| Loan product dropdown options
|--------------------------------------------------------------------------
| All labels here are generic. Rename them, or add and remove choices, to
| suit your organization. The array KEY is what is stored in the database;
| only change keys that you are adding yourself.
*/

return [
    'term_units' => ['days' => 'Days', 'weeks' => 'Weeks', 'months' => 'Months', 'years' => 'Years'],

    'frequencies' => [
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'semi_monthly' => 'Semi-monthly',
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'annual' => 'Annual',
        'one_time' => 'One-time payment',
    ],

    // How many of each period fit in a year (used by the computation engine)
    'periods_per_year' => [
        'daily' => 365, 'weekly' => 52, 'semi_monthly' => 24, 'monthly' => 12,
        'quarterly' => 4, 'annual' => 1, 'one_time' => 1,
    ],

    'interest_types' => [
        'flat' => 'Fixed / Flat Rate',
        'diminishing' => 'Diminishing Balance',
        'add_on' => 'Add-on Interest',
        'simple' => 'Simple Interest',
        'compound' => 'Compound Interest',
        'interest_free' => 'Interest-Free',
    ],

    'interest_bases' => [
        'daily' => 'Daily', 'weekly' => 'Weekly', 'semi_monthly' => 'Semi-monthly', 'monthly' => 'Monthly',
        'quarterly' => 'Quarterly', 'annual' => 'Annual', 'per_term' => 'Per Term',
    ],

    'amortization_methods' => [
        'equal_payment' => 'Equal Payment',
        'equal_principal' => 'Equal Principal',
        'custom' => 'Custom Schedule',
    ],

    'deduction_types' => ['fixed' => 'Fixed Amount', 'percentage' => 'Percentage', 'none' => 'None'],

    'deduction_bases' => [
        'loan_amount' => 'Based on Loan Amount',
        'approved_amount' => 'Based on Approved Amount',
        'principal' => 'Based on Principal',
        'per_transaction' => 'Per Transaction',
    ],

    'deduction_timings' => [
        'upon_release' => 'Deduct Upon Release',
        'add_to_payable' => 'Add to Payable',
        'first_payment' => 'Deduct from First Payment',
    ],

    // Suggestions only: the admin can type any deduction name
    'deduction_names' => [
        'Service Fee', 'Processing Fee', 'Filing Fee', 'Insurance Fee', 'Notarial Fee',
        'Membership Fee', 'Savings Deposit', 'Capital Share', 'Advance Interest', 'Other Deduction',
    ],

    // Suggestions only: the admin can type any category
    'categories' => [
        'Regular Loan', 'Emergency Loan', 'Salary Loan', 'Business Loan', 'Educational Loan', 'Calamity Loan',
        'Agricultural Loan', 'Motorcycle Loan', 'Appliance Loan', 'Personal Loan', 'Custom',
    ],

    'penalty_types' => ['fixed' => 'Fixed Amount', 'percentage' => 'Percentage', 'none' => 'None'],

    'penalty_bases' => [
        'per_day' => 'Per Day', 'per_week' => 'Per Week', 'per_month' => 'Per Month', 'per_missed_payment' => 'Per Missed Payment',
    ],

    'approval_levels' => [
        'officer' => 'Officer', 'manager' => 'Manager', 'committee' => 'Committee', 'board' => 'Board', 'custom' => 'Custom',
    ],

    'release_methods' => [
        'cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'check' => 'Check', 'ewallet' => 'E-wallet', 'other' => 'Other',
    ],

    'documents' => [
        'valid_id' => 'Valid ID',
        'proof_of_income' => 'Proof of Income',
        'employment_certificate' => 'Employment Certificate',
        'business_permit' => 'Business Permit',
        'address_proof' => 'Address Proof',
        'application_form' => 'Application Form',
        'other' => 'Other Documents',
    ],
];
