<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Tenant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TellerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Tenant::set(Organization::value('id'));   // work inside the demo organization
    }

    private function as(string $role): static
    {
        return $this->actingAs(User::where('role', $role)->firstOrFail());
    }

    private function newMember(): Member
    {
        return Member::create(['member_no' => 'MEM-T-'.random_int(100, 999), 'first_name' => 'Tel', 'last_name' => 'Ler', 'date_joined' => now(), 'status' => 'active']);
    }

    public function test_deposit_then_withdrawal_with_slip_and_running_balance(): void
    {
        $m = $this->newMember();

        $response = $this->as('cashier')->post('/savings', [
            'kind' => 'savings_deposit', 'member_id' => $m->id, 'amount' => 1000, 'payment_method' => 'check', 'payment_ref' => 'CHK-77', 'remarks' => 'Salary',
        ]);
        $tx = Transaction::latest('id')->first();
        $response->assertRedirect(route('transactions.receipt', $tx));

        $this->assertSame('check', $tx->payment_method);
        $this->assertSame('CHK-77', $tx->payment_ref);
        $this->assertEquals(1000, $tx->balance_after);

        $this->as('cashier')->post('/savings', ['kind' => 'savings_withdrawal', 'member_id' => $m->id, 'amount' => 400, 'payment_method' => 'cash']);
        $this->assertEquals(600, Transaction::latest('id')->first()->balance_after);

        $this->as('cashier')->get(route('transactions.receipt', $tx))->assertOk()->assertSee('CHK-77')->assertSee('Savings balance after')->assertSee('₱1,000.00');
    }

    public function test_loan_payment_from_the_teller_window(): void
    {
        $loan = Loan::where('status', 'released')->where('balance', '>', 0)->first();
        $before = (float) $loan->balance;

        $this->as('cashier')->post('/savings', [
            'kind' => 'loan_payment', 'member_id' => $loan->member_id, 'loan_id' => $loan->id, 'amount' => 500, 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $tx = Transaction::latest('id')->first();
        $this->assertSame('loan_payment', $tx->type);
        $this->assertEquals($before - 500, $loan->fresh()->balance);
        $this->assertEquals($before - 500, $tx->balance_after);                    // loan balance after

        // a loan is required, and it must belong to the member
        $this->as('cashier')->post('/savings', ['kind' => 'loan_payment', 'member_id' => $loan->member_id, 'amount' => 10, 'payment_method' => 'cash'])->assertSessionHasErrors('loan_id');
        $other = $this->newMember();
        $this->as('cashier')->post('/savings', ['kind' => 'loan_payment', 'member_id' => $other->id, 'loan_id' => $loan->id, 'amount' => 10, 'payment_method' => 'cash'])->assertSessionHasErrors('loan_id');
    }

    public function test_roles_for_each_kind_of_transaction(): void
    {
        $m = $this->newMember();
        $loan = Loan::where('status', 'released')->where('balance', '>', 0)->first();

        // staff: deposits yes, loan payments no
        $this->as('staff')->post('/savings', ['kind' => 'savings_deposit', 'member_id' => $m->id, 'amount' => 50, 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $this->as('staff')->post('/savings', ['kind' => 'loan_payment', 'member_id' => $loan->member_id, 'loan_id' => $loan->id, 'amount' => 50, 'payment_method' => 'cash'])->assertForbidden();

        // auditor and loan officer: can look, cannot post
        $this->as('auditor')->get('/savings')->assertOk()->assertSee('cannot post transactions');
        $this->as('auditor')->post('/savings', ['kind' => 'savings_deposit', 'member_id' => $m->id, 'amount' => 50, 'payment_method' => 'cash'])->assertForbidden();
        $this->as('loan_officer')->post('/savings', ['kind' => 'loan_payment', 'member_id' => $loan->member_id, 'loan_id' => $loan->id, 'amount' => 50, 'payment_method' => 'cash'])->assertForbidden();

        // each role sees only its tabs
        $this->as('cashier')->get('/savings')->assertSee('Loan Payment')->assertSee('Withdrawal');
        $this->as('staff')->get('/savings')->assertDontSee('data-kind="loan_payment"', false);
    }

    public function test_minimum_balance_rule(): void
    {
        $m = $this->newMember();
        $this->as('cashier')->post('/savings', ['kind' => 'savings_deposit', 'member_id' => $m->id, 'amount' => 1000, 'payment_method' => 'cash']);

        $this->as('super_admin')->put('/settings', [
            'coop_name' => 'X', 'branch_name' => 'Y', 'default_interest' => 12, 'par_threshold' => 5, 'collection_target' => 95, 'min_balance' => 200, 'allocation_preset' => 'penalty_first', 'share_withdrawal_policy' => 'not_allowed', 'regular_savings' => 0, 'regular_share' => 0,
        ])->assertSessionHasNoErrors();
        $this->assertEquals(200, Setting::get('min_balance'));

        $this->as('cashier')->post('/savings', ['kind' => 'savings_withdrawal', 'member_id' => $m->id, 'amount' => 900, 'payment_method' => 'cash'])->assertSessionHasErrors('amount');
        $this->as('cashier')->post('/savings', ['kind' => 'savings_withdrawal', 'member_id' => $m->id, 'amount' => 800, 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertEquals(200, $m->savingsBalance());
    }

    public function test_account_summary_and_statement(): void
    {
        $loan = Loan::where('status', 'released')->where('balance', '>', 0)->first();
        $m = $loan->member;

        $this->as('cashier')->get("/savings/account/{$m->id}")->assertOk()
            ->assertJsonPath('member_no', $m->member_no)
            ->assertJsonPath('loans.0.loan_no', $loan->loan_no)
            ->assertJsonStructure(['savings', 'min_balance', 'shares_paid', 'recent', 'statement_url']);

        $fresh = $this->newMember();
        $this->as('cashier')->post('/savings', ['kind' => 'savings_deposit', 'member_id' => $fresh->id, 'amount' => 300, 'payment_method' => 'cash']);
        $this->as('cashier')->post('/savings', ['kind' => 'savings_withdrawal', 'member_id' => $fresh->id, 'amount' => 100, 'payment_method' => 'cash']);

        $page = $this->as('cashier')->get("/savings/{$fresh->id}/statement")->assertOk();
        $page->assertSee('Opening balance')->assertSee('₱300.00')->assertSee('₱100.00')->assertSee('₱200.00');   // deposit, withdrawal, closing balance

        // opening balance picks up what happened before the chosen period
        $future = now()->addDay()->toDateString();
        $this->as('cashier')->get("/savings/{$fresh->id}/statement?from={$future}&to={$future}")->assertOk()->assertSee('No transactions in this period');
    }

    public function test_validation_and_voided_slip(): void
    {
        $m = $this->newMember();

        $this->as('cashier')->post('/savings', ['kind' => 'savings_deposit', 'member_id' => $m->id, 'amount' => 0, 'payment_method' => 'cash'])->assertSessionHasErrors('amount');
        $this->as('cashier')->post('/savings', ['kind' => 'savings_deposit', 'member_id' => $m->id, 'amount' => 10, 'payment_method' => 'bitcoin'])->assertSessionHasErrors('payment_method');
        $this->as('cashier')->post('/savings', ['kind' => 'share_deposit', 'member_id' => $m->id, 'amount' => 10, 'payment_method' => 'cash'])->assertSessionHasErrors('kind');
        $this->as('cashier')->post('/savings', ['kind' => 'savings_withdrawal', 'member_id' => $m->id, 'amount' => 10, 'payment_method' => 'cash'])->assertSessionHasErrors('amount');   // no money yet

        $this->as('cashier')->post('/savings', ['kind' => 'savings_deposit', 'member_id' => $m->id, 'amount' => 70, 'payment_method' => 'cash']);
        $tx = Transaction::latest('id')->first();
        $this->as('accountant')->post("/transactions/{$tx->id}/void", ['reason' => 'wrong member']);
        $this->as('cashier')->get(route('transactions.receipt', $tx))->assertOk()->assertSee('VOID');
    }

    public function test_teller_page_shows_today_totals_and_members_page_links_statement(): void
    {
        $this->as('manager')->get('/savings')->assertOk()->assertSee('Teller Window')->assertSee('Deposits today')->assertSee('Savings Accounts');
        $this->as('manager')->get('/members/'.Member::first()->id)->assertOk()->assertSee('Savings statement');
    }
}
