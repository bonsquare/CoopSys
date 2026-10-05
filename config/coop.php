<?php

/*
|--------------------------------------------------------------------------
| CoopSys roles and permissions
|--------------------------------------------------------------------------
| 'view'   = who can open the module's pages
| 'manage' = who can add/edit things in the module
| Extra levels (approve, disburse, void) are used for special buttons.
| Super Admin can always do everything.
*/

$all = ['super_admin', 'manager', 'accountant', 'cashier', 'loan_officer', 'staff', 'auditor'];

return [
    'roles' => [
        'super_admin' => 'Super Admin',
        'manager' => 'Manager',
        'accountant' => 'Accountant',
        'cashier' => 'Cashier',
        'loan_officer' => 'Loan Officer',
        'staff' => 'Staff',
        'auditor' => 'Auditor',
    ],

    'permissions' => [
        'dashboard' => ['view' => $all],
        'members' => ['view' => $all, 'manage' => ['super_admin', 'manager', 'staff', 'loan_officer']],
        'share' => ['view' => $all, 'manage' => ['super_admin', 'manager', 'cashier', 'staff']],
        'savings' => ['view' => $all, 'manage' => ['super_admin', 'manager', 'cashier', 'staff']],
        'loans' => ['view' => $all, 'manage' => ['super_admin', 'manager', 'loan_officer'],
            'approve' => ['super_admin', 'manager'],
            'disburse' => ['super_admin', 'manager', 'cashier']],
        'loan_products' => ['view' => $all, 'manage' => ['super_admin', 'manager']],
        'collections' => ['view' => $all, 'manage' => ['super_admin', 'manager', 'cashier'], 'reverse' => ['super_admin', 'manager', 'accountant']],
        'receipts' => ['view' => $all, 'manage' => ['super_admin', 'manager', 'cashier']],
        'disbursements' => ['view' => $all, 'manage' => ['super_admin', 'manager', 'cashier', 'accountant']],
        'ledger' => ['view' => $all, 'void' => ['super_admin', 'accountant']],
        'accounting' => ['view' => ['super_admin', 'manager', 'accountant', 'auditor'], 'manage' => ['super_admin', 'accountant'], 'close' => ['super_admin', 'accountant']],
        'reports' => ['view' => ['super_admin', 'manager', 'accountant', 'auditor', 'loan_officer']],
        'users' => ['view' => ['super_admin'], 'manage' => ['super_admin']],
        'settings' => ['view' => ['super_admin'], 'manage' => ['super_admin']],
    ],

    // How money was received or paid (shown on the teller window and slips)
    'payment_methods' => [
        'cash' => 'Cash',
        'check' => 'Check',
        'bank_transfer' => 'Bank Transfer',
        'ewallet' => 'E-wallet',
        'other' => 'Other',
    ],

    // Default settings (editable on the Settings page)
    'defaults' => [
        'coop_name' => 'CoopSys Cooperative',
        'branch_name' => 'Main Cooperative Branch - District 1',
        'default_interest' => '12',
        'par_threshold' => '5',
        'collection_target' => '95',
        'min_balance' => '0',
        'allocation_preset' => 'penalty_first',
        'allocation_custom' => 'penalty,interest,fees,principal,savings,share',
        'share_withdrawal_policy' => 'not_allowed',
        'regular_savings' => '0',
        'regular_share' => '0',
        'board_meeting' => '',
        'board_note' => '',
    ],

    // Can new organizations sign themselves up at /register? Set COOP_REGISTRATION=false to close sign-ups.
    'registration' => (bool) env('COOP_REGISTRATION', true),

    // Free trial length for a new organization
    'trial_days' => (int) env('COOP_TRIAL_DAYS', 30),

    // Plans and their limits (null = unlimited). Change a customer's plan with: php artisan organization:plan CODE PLAN
    'plans' => [
        'trial' => ['label' => 'Free trial', 'limits' => ['users' => 5, 'members' => 100]],
        'standard' => ['label' => 'Standard', 'limits' => ['users' => 25, 'members' => 5000]],
        'unlimited' => ['label' => 'Unlimited', 'limits' => ['users' => null, 'members' => null]],
    ],
];
