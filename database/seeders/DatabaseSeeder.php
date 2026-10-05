<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\Setting;
use App\Models\User;
use App\Services\LoanService;
use App\Services\TransactionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Run with:  php artisan migrate:fresh --seed
 *
 * DEMO LOGINS (local testing only, change or delete them before going live):
 *   Every demo user below uses the password in $demoPassword.
 *   admin@coopsys.test is the Super Admin.
 */
class DatabaseSeeder extends Seeder
{
    private string $demoPassword = 'password';

    public function run(): void
    {
        $this->chartOfAccounts();
        $this->call(LoanProductSeeder::class);
        $this->settings();
        $users = $this->users();

        // Post demo transactions as the Super Admin so they show "Handled By"
        Auth::login($users['super_admin']);
        $this->demoData();
        Auth::logout();
    }

    private function chartOfAccounts(): void
    {
        // The last column marks accounts the system posts to automatically (they cannot be deleted or deactivated)
        $accounts = [
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
        ];
        foreach ($accounts as [$code, $name, $type, $system]) {
            Account::updateOrCreate(['code' => $code], ['name' => $name, 'type' => $type, 'is_system' => $system, 'report_group' => config("accounting.groups.$code")]);
        }
    }

    private function settings(): void
    {
        Setting::put('board_meeting', now()->addDays(20)->toDateString());
        Setting::put('board_note', 'Annual General Assembly Preparation');
    }

    private function users(): array
    {
        $list = [
            'super_admin' => ['Admin User', 'admin@coopsys.test'],
            'manager' => ['Elena Vance', 'manager@coopsys.test'],
            'accountant' => ['Ana Accountant', 'accountant@coopsys.test'],
            'cashier' => ['Maria Santos', 'cashier@coopsys.test'],
            'loan_officer' => ['Arturo Perez', 'loan@coopsys.test'],
            'staff' => ['Sam Staff', 'staff@coopsys.test'],
            'auditor' => ['Audrey Auditor', 'auditor@coopsys.test'],
        ];

        $users = [];
        foreach ($list as $role => [$name, $email]) {
            $users[$role] = User::updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'role' => $role, 'is_active' => true, 'password' => $this->demoPassword]
            );
        }

        return $users;
    }

    private function demoData(): void
    {
        $tx = app(TransactionService::class);
        $loans = app(LoanService::class);
        $reg = LoanProduct::where('code', 'REG')->first();
        $emg = LoanProduct::where('code', 'EMG')->first();
        $bus = LoanProduct::where('code', 'BUS')->first();
        $edu = LoanProduct::where('code', 'EDU')->first();

        $people = [
            ['Eduardo', 'Santos', 50000], ['Rosalinda', 'Dela Cruz', 30000], ['Danilo', 'Ramos', 40000],
            ['Teresa', 'Bautista', 20000], ['Felix', 'Almario', 25000], ['Corazon', 'Villanueva', 60000],
            ['Ramon', 'Garcia', 15000], ['Luz', 'Mendoza', 35000], ['Jose', 'Reyes', 10000], ['Nena', 'Cruz', 20000],
        ];

        $members = [];
        foreach ($people as $i => [$first, $last, $subscribed]) {
            $members[] = Member::create([
                'member_no' => Member::nextNumber(),
                'first_name' => $first,
                'last_name' => $last,
                'gender' => $i % 2 ? 'Female' : 'Male',
                'address' => 'Barangay '.($i + 1).', District 1',
                'contact_no' => '0917-000-'.str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT),
                'email' => strtolower($first).'@example.com',
                'occupation' => ['Farmer', 'Teacher', 'Vendor', 'Driver', 'Nurse'][$i % 5],
                'date_joined' => now()->subMonths(18 - $i)->toDateString(),
                'subscribed_shares' => $subscribed,
                'status' => $i === 9 ? 'inactive' : 'active',
            ]);
        }

        // Share capital + savings spread over the last few days / weeks
        foreach ($members as $i => $m) {
            if ($m->status !== 'active') {
                continue;
            }
            $tx->post(['type' => 'share_deposit', 'amount' => $m->subscribed_shares * 0.6, 'member_id' => $m->id,
                'posted_at' => now()->subDays(40 - $i), 'remarks' => 'Initial paid-up capital']);
            $tx->post(['type' => 'savings_deposit', 'amount' => 5000 + $i * 1500, 'member_id' => $m->id,
                'posted_at' => now()->subDays(30 - $i)]);
        }
        $tx->post(['type' => 'savings_withdrawal', 'amount' => 2000, 'member_id' => $members[5]->id, 'posted_at' => now()->subDays(3)]);
        $tx->post(['type' => 'other_receipt', 'amount' => 1500, 'member_id' => $members[1]->id, 'remarks' => 'Membership fee', 'posted_at' => now()->subDays(12)]);
        $tx->post(['type' => 'expense', 'amount' => 3200, 'remarks' => 'Office supplies', 'posted_at' => now()->subDays(6)]);

        // Loans in different stages
        $running = $loans->apply($members[0], $reg, 60000, 12, 'Farm inputs');
        $loans->approve($running);
        $loans->release($running);
        $tx->post(['type' => 'loan_payment', 'amount' => $running->fresh()->monthly_amortization,
            'member_id' => $members[0]->id, 'loan_id' => $running->id, 'posted_at' => now()->subDays(2)]);

        $overdue = $loans->apply($members[2], $emg, 30000, 6, 'Emergency');
        $loans->approve($overdue);
        $loans->release($overdue);
        // make this one old, so it is past due
        $overdue->update(['released_at' => now()->subMonths(9)->toDateString(), 'maturity_date' => now()->subMonths(3)->toDateString()]);

        $approved = $loans->apply($members[3], $bus, 25000, 10, 'Small business capital');
        $loans->approve($approved);

        $loans->apply($members[4], $edu, 15000, 6, 'School tuition');

        $paid = $loans->apply($members[5], $emg, 10000, 3, 'Appliance');
        $loans->approve($paid);
        $loans->release($paid);
        $tx->post(['type' => 'loan_payment', 'amount' => $paid->fresh()->balance,
            'member_id' => $members[5]->id, 'loan_id' => $paid->id, 'posted_at' => now()->subDays(1)]);
    }
}
