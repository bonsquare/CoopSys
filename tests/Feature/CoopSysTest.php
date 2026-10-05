<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Models\JournalLine;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoopSysTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function as(string $role): static
    {
        return $this->actingAs(User::where('role', $role)->firstOrFail());
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/members')->assertRedirect('/login');
    }

    public function test_login_and_inactive_account(): void
    {
        $this->post('/login', ['email' => 'admin@coopsys.test', 'password' => 'password'])->assertRedirect('/dashboard');
        $this->post('/logout');

        User::where('email', 'staff@coopsys.test')->update(['is_active' => false]);
        $this->post('/login', ['email' => 'staff@coopsys.test', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'admin@coopsys.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
    }

    public function test_every_role_gets_the_right_pages(): void
    {
        $pages = [
            '/dashboard' => 'dashboard', '/members' => 'members', '/share-capital' => 'share', '/savings' => 'savings',
            '/loans' => 'loans', '/loan-products' => 'loan_products', '/cash-receipts' => 'receipts', '/cash-disbursements' => 'disbursements',
            '/transactions' => 'ledger', '/accounting' => 'accounting', '/reports' => 'reports',
            '/users' => 'users', '/settings' => 'settings',
        ];

        foreach (array_keys(config('coop.roles')) as $role) {
            foreach ($pages as $url => $module) {
                $expected = User::where('role', $role)->first()->canAccess($module) ? 200 : 403;
                $this->as($role)->get($url)->assertStatus($expected, "$role on $url");
            }
        }
    }

    public function test_dashboard_periods_and_exports_work(): void
    {
        foreach (['today', 'month', 'quarter'] as $p) {
            $this->as('manager')->get("/dashboard?period=$p")->assertOk();
            $this->as('manager')->get("/transactions/export?period=$p")->assertOk();
        }
        foreach (array_keys(ReportController::REPORTS) as $r) {
            $this->as('accountant')->get("/reports?report=$r")->assertOk();
            $this->as('accountant')->get("/reports/export?report=$r")->assertOk();
        }
        $this->as('accountant')->get('/accounting')->assertOk()->assertSee('books are balanced');
    }

    public function test_detail_pages_render(): void
    {
        $this->as('super_admin')->get('/members/'.Member::first()->id)->assertOk();
        $this->as('super_admin')->get('/members/create')->assertOk();
        $this->as('super_admin')->get('/loans/create')->assertOk();
        $this->as('super_admin')->get('/loans/'.Loan::first()->id)->assertOk();
        $this->as('super_admin')->get('/transactions/'.Transaction::first()->id)->assertOk();
        $this->as('super_admin')->get('/users/create')->assertOk();
        $this->as('super_admin')->get('/account')->assertOk();
    }

    public function test_member_can_be_added_and_edited_without_losing_subscription(): void
    {
        $this->as('staff')->post('/members', [
            'first_name' => 'Test', 'last_name' => 'Person', 'date_joined' => now()->toDateString(),
        ])->assertRedirect();
        $m = Member::where('last_name', 'Person')->firstOrFail();

        $this->as('staff')->post("/share-capital/{$m->id}/subscription", ['subscribed_shares' => 5000]);
        $this->as('staff')->put("/members/{$m->id}", ['first_name' => 'Changed', 'last_name' => 'Person', 'date_joined' => now()->toDateString()]);

        $m->refresh();
        $this->assertSame('Changed', $m->first_name);
        $this->assertEquals(5000, $m->subscribed_shares);
    }

    public function test_share_and_savings_rules(): void
    {
        $m = Member::create(['member_no' => 'MEM-T-1', 'first_name' => 'A', 'last_name' => 'B', 'date_joined' => now(), 'status' => 'active']);

        // no subscription yet
        $this->as('cashier')->post('/share-capital/payment', ['member_id' => $m->id, 'amount' => 100])->assertSessionHasErrors('amount');

        $m->update(['subscribed_shares' => 1000]);
        $this->as('cashier')->post('/share-capital/payment', ['member_id' => $m->id, 'amount' => 600])->assertSessionHasNoErrors();
        $this->as('cashier')->post('/share-capital/payment', ['member_id' => $m->id, 'amount' => 500])->assertSessionHasErrors('amount'); // only 400 left

        $this->as('cashier')->post('/savings', ['kind' => 'savings_deposit', 'member_id' => $m->id, 'amount' => 300])->assertSessionHasNoErrors();
        $this->as('cashier')->post('/savings', ['kind' => 'savings_withdrawal', 'member_id' => $m->id, 'amount' => 300.01])->assertSessionHasErrors('amount');
        $this->as('cashier')->post('/savings', ['kind' => 'savings_withdrawal', 'member_id' => $m->id, 'amount' => 300])->assertSessionHasNoErrors();
        $this->assertEquals(0, $m->fresh()->savingsBalance());
    }

    public function test_loan_lifecycle_and_accounting_balance(): void
    {
        $m = Member::where('status', 'active')->first();

        $this->as('loan_officer')->post('/loans', ['member_id' => $m->id, 'loan_product_id' => LoanProduct::where('code', 'EMG')->value('id'), 'principal' => 12000, 'term' => 6])->assertRedirect();
        $loan = Loan::latest('id')->first();
        $this->assertEquals(600, $loan->total_interest);       // 12000 x 10% x 6/12
        $this->assertEquals(12600, $loan->total_payable);
        $this->assertEquals(2100, $loan->monthly_amortization);

        // roles that may not approve / release
        $this->as('loan_officer')->post("/loans/{$loan->id}/approve")->assertForbidden();
        $this->as('manager')->post("/loans/{$loan->id}/release")->assertSessionHasErrors('loan'); // not approved yet

        $this->as('manager')->post("/loans/{$loan->id}/approve")->assertSessionHasNoErrors();
        $this->as('cashier')->post("/loans/{$loan->id}/release")->assertSessionHasNoErrors();
        $loan->refresh();
        $this->assertSame('released', $loan->status);
        $this->assertEquals(12600, $loan->balance);

        // overpayment rejected, normal payment accepted
        $this->as('cashier')->post('/cash-receipts', ['type' => 'loan_payment', 'member_id' => $m->id, 'loan_id' => $loan->id, 'amount' => 99999])->assertSessionHasErrors('amount');
        $this->as('cashier')->post('/cash-receipts', ['type' => 'loan_payment', 'member_id' => $m->id, 'loan_id' => $loan->id, 'amount' => 2100])->assertSessionHasNoErrors();
        $this->assertEquals(10500, $loan->fresh()->balance);

        // pay it off
        $this->as('cashier')->post('/cash-receipts', ['type' => 'loan_payment', 'member_id' => $m->id, 'loan_id' => $loan->id, 'amount' => 10500]);
        $this->assertSame('paid', $loan->fresh()->status);

        // books still balanced
        $this->assertEqualsWithDelta(JournalLine::sum('debit'), JournalLine::sum('credit'), 0.001);
    }

    public function test_void_reverses_everything(): void
    {
        $m = Member::where('status', 'active')->first();
        $before = $m->savingsBalance();

        $this->as('cashier')->post('/savings', ['kind' => 'savings_deposit', 'member_id' => $m->id, 'amount' => 777]);
        $tx = Transaction::latest('id')->first();
        $this->assertEquals($before + 777, $m->savingsBalance());

        $this->as('cashier')->post("/transactions/{$tx->id}/void", ['reason' => 'mistake'])->assertForbidden();
        $this->as('accountant')->post("/transactions/{$tx->id}/void", ['reason' => 'mistake'])->assertSessionHasNoErrors();

        $this->assertSame('voided', $tx->fresh()->status);
        $this->assertEquals($before, $m->savingsBalance());
        $this->assertEqualsWithDelta(JournalLine::sum('debit'), JournalLine::sum('credit'), 0.001);

        // cannot void twice
        $this->as('accountant')->post("/transactions/{$tx->id}/void", ['reason' => 'again'])->assertSessionHasErrors('transaction');
    }

    public function test_user_management_protects_last_super_admin(): void
    {
        $admin = User::where('role', 'super_admin')->first();

        $this->as('super_admin')->post("/users/{$admin->id}/toggle")->assertSessionHasErrors('user');           // own account
        $this->as('super_admin')->put("/users/{$admin->id}", ['name' => $admin->name, 'email' => $admin->email, 'role' => 'staff'])
            ->assertSessionHasErrors('role');                                                                        // last super admin

        $this->as('super_admin')->post('/users', ['name' => 'N', 'email' => 'n@x.test', 'role' => 'cashier', 'password' => 'longenough1', 'password_confirmation' => 'longenough1'])
            ->assertRedirect('/users');
        $this->as('cashier')->get('/users')->assertForbidden();
    }
}
