<?php

/*
|--------------------------------------------------------------------------
| Payment collection options
|--------------------------------------------------------------------------
| All labels are generic. Rename them, or add and remove choices, to suit your
| organization. The array KEY is what is stored in the database.
*/

return [
    // What the receipt is mainly for. It only pre-fills the first allocation row.
    'types' => [
        'loan_payment' => ['label' => 'Loan Payment', 'row' => null],
        'savings_deposit' => ['label' => 'Savings Deposit', 'row' => 'savings_deposit'],
        'savings_withdrawal' => ['label' => 'Savings Withdrawal', 'row' => 'savings_withdrawal'],
        'share_contribution' => ['label' => 'Share Capital Contribution', 'row' => 'share_contribution'],
        'share_withdrawal' => ['label' => 'Share Capital Withdrawal', 'row' => 'share_withdrawal'],
        'membership_fee' => ['label' => 'Membership Fee Collection', 'row' => 'membership_fee'],
        'insurance' => ['label' => 'Insurance Collection', 'row' => 'insurance'],
        'penalty' => ['label' => 'Penalty Collection', 'row' => 'loan_penalty'],
        'other' => ['label' => 'Other Collection', 'row' => 'other_income'],
        'mixed' => ['label' => 'Mixed Collection', 'row' => null],
    ],

    // Payment breakdown choices.
    //   tx_type   = the transaction type posted to the ledger
    //   direction = in (money received) or out (money paid out)
    //   kind      = which account the row belongs to: loan, savings, share or other
    //   charge    = counts as a charge / fee in the transaction summary
    'allocations' => [
        'loan_principal' => ['label' => 'Loan Principal', 'tx_type' => 'loan_principal', 'direction' => 'in', 'kind' => 'loan', 'charge' => false],
        'loan_interest' => ['label' => 'Loan Interest', 'tx_type' => 'loan_interest', 'direction' => 'in', 'kind' => 'loan', 'charge' => false],
        'loan_penalty' => ['label' => 'Loan Penalty', 'tx_type' => 'loan_penalty', 'direction' => 'in', 'kind' => 'loan', 'charge' => true],
        'loan_fees' => ['label' => 'Loan Fees / Charges', 'tx_type' => 'loan_fees', 'direction' => 'in', 'kind' => 'loan', 'charge' => true],
        'savings_deposit' => ['label' => 'Regular Savings Deposit', 'tx_type' => 'savings_deposit', 'direction' => 'in', 'kind' => 'savings', 'charge' => false],
        'savings_withdrawal' => ['label' => 'Regular Savings Withdrawal', 'tx_type' => 'savings_withdrawal', 'direction' => 'out', 'kind' => 'savings', 'charge' => false],
        'share_contribution' => ['label' => 'Share Capital Contribution', 'tx_type' => 'share_deposit', 'direction' => 'in', 'kind' => 'share', 'charge' => false],
        'share_withdrawal' => ['label' => 'Share Capital Withdrawal', 'tx_type' => 'share_withdrawal', 'direction' => 'out', 'kind' => 'share', 'charge' => false],
        'membership_fee' => ['label' => 'Membership Fee', 'tx_type' => 'membership_fee', 'direction' => 'in', 'kind' => 'other', 'charge' => true],
        'insurance' => ['label' => 'Insurance', 'tx_type' => 'insurance', 'direction' => 'in', 'kind' => 'other', 'charge' => true],
        'other_income' => ['label' => 'Other Income', 'tx_type' => 'other_receipt', 'direction' => 'in', 'kind' => 'other', 'charge' => true],
        'other_receivable' => ['label' => 'Other Receivable Payment', 'tx_type' => 'other_receivable', 'direction' => 'in', 'kind' => 'other', 'charge' => false],
        'other_deduction' => ['label' => 'Other Deduction / Adjustment', 'tx_type' => 'other_deduction', 'direction' => 'in', 'kind' => 'other', 'charge' => true],
    ],

    'statuses' => ['draft' => 'Draft', 'posted' => 'Posted', 'cancelled' => 'Cancelled'],

    // Order in which a payment is used up by "Auto Allocate".
    // penalty, interest, fees, principal belong to the loan; savings and share are the member's other accounts.
    'priority_presets' => [
        'penalty_first' => ['label' => 'Penalty first', 'order' => ['penalty', 'interest', 'fees', 'principal', 'savings', 'share']],
        'interest_first' => ['label' => 'Interest first', 'order' => ['interest', 'penalty', 'fees', 'principal', 'savings', 'share']],
        'principal_first' => ['label' => 'Principal first', 'order' => ['principal', 'interest', 'penalty', 'fees', 'savings', 'share']],
        'fees_first' => ['label' => 'Fees first', 'order' => ['fees', 'penalty', 'interest', 'principal', 'savings', 'share']],
        'savings_first' => ['label' => 'Savings first', 'order' => ['savings', 'penalty', 'interest', 'fees', 'principal', 'share']],
        'custom' => ['label' => 'Custom order', 'order' => null],
    ],

    'priority_items' => [
        'penalty' => 'Penalty',
        'interest' => 'Interest',
        'fees' => 'Fees / Charges',
        'principal' => 'Principal',
        'savings' => 'Savings',
        'share' => 'Share Capital',
    ],

    // Can a member take out share capital?
    'share_withdrawal_policies' => [
        'not_allowed' => 'Not allowed',
        'inactive_only' => 'Only when the member is inactive',
        'allowed' => 'Allowed (up to the amount paid in)',
    ],
];
