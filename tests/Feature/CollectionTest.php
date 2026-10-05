<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Collection;
use App\Models\JournalLine;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LoanScheduleService;
use App\Services\LoanService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionTest extends TestCase
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

    private function newMember(array $over = []): Member
    {
        return Member::create(array_merge(['member_no' => 'MEM-C-'.random_int(1000, 9999), 'first_name' => 'Col', 'last_name' => 'Lect', 'date_joined' => now(), 'status' => 'active', 'subscribed_shares' => 5000], $over));
    }

    /** A released Regular Loan of 12,000 over 6 months, released $monthsAgo months ago. (Interest 720, payment 2,120 a month.) */
    private function overdueLoan(Member $member, int $monthsAgo = 2): Loan
    {
        $this->as('manager');
        $service = app(LoanService::class);
        $loan = $service->apply($member, LoanProduct::where('code', 'REG')->first(), 12000, 6, 'test');
        $service->approve($loan);
        $service->release($loan);
        $loan->update(['released_at' => now()->subMonths($monthsAgo)->toDateString(), 'maturity_date' => now()->subMonths($monthsAgo)->addMonths(6)->toDateString()]);

        return $loan->fresh();
    }

    private function payload(Member $m, array $lines, array $over = []): array
    {
        $net = 0;
        foreach ($lines as $line) {
            $out = config("collections.allocations.{$line['allocation_type']}.direction") === 'out';
            $net += $out ? -$line['amount'] : $line['amount'];
        }

        return array_merge([
            'action' => 'post', 'member_id' => $m->id, 'collection_type' => 'mixed', 'collection_date' => now()->toDateString(),
            'payment_method' => 'cash', 'total_amount' => abs($net), 'cash_tendered' => abs($net), 'lines' => $lines,
        ], $over);
    }

    private function balanced(): void
    {
        $this->assertEqualsWithDelta(JournalLine::sum('debit'), JournalLine::sum('credit'), 0.001);
    }

    // ---------- Schedule, penalty and suggestions ----------

    public function test_due_amounts_and_penalty_after_grace(): void
    {
        $m = $this->newMember();
        $loan = $this->overdueLoan($m);
        $a = app(LoanScheduleService::class)->analyze($loan);

        $this->assertEquals(4000, $a['due_now']['principal']);        // two installments are due
        $this->assertEquals(240, $a['due_now']['interest']);
        $this->assertSame(1, $a['penalty']['missed']);                // only the first one is overdue
        $this->assertEquals(42.40, $a['penalty']['due']);             // 2% of the 2,120 shortfall for one month
        $this->assertTrue($a['past_due']);

        // inside the grace period there is no penalty
        $fresh = $this->overdueLoan($this->newMember(), 0);
        $this->assertEquals(0, app(LoanScheduleService::class)->analyze($fresh)['penalty']['due']);

        // a product without a penalty never charges one
        LoanProduct::where('code', 'REG')->update(['penalty_type' => 'none', 'penalty_value' => 0]);
        $this->assertEquals(0, app(LoanScheduleService::class)->analyze($loan->fresh())['penalty']['due']);
    }

    public function test_fixed_penalty_per_day(): void
    {
        $m = $this->newMember();
        $loan = $this->overdueLoan($m);
        LoanProduct::where('code', 'REG')->update(['penalty_type' => 'fixed', 'penalty_value' => 10, 'penalty_basis' => 'per_day', 'grace_days' => 0]);

        $days = (int) abs($loan->released_at->copy()->addMonthsNoOverflow(1)->diffInDays(now()->startOfDay()));
        $this->assertEquals(10 * $days, app(LoanScheduleService::class)->analyze($loan->fresh())['penalty']['due']);
    }

    public function test_auto_allocation_follows_the_priority_setting(): void
    {
        $m = $this->newMember();
        $loan = $this->overdueLoan($m);
        $url = "/collections/suggest?member_id={$m->id}&type=loan_payment&amount=";

        // default: penalty, interest, fees, principal
        $rows = $this->as('cashier')->getJson($url.'5000')->assertOk()->json();
        $this->assertSame(['loan_penalty', 'loan_interest', 'loan_principal'], array_column($rows['rows'], 'allocation_type'));
        $this->assertEquals([42.40, 240, 4000], array_column($rows['rows'], 'amount'));
        $this->assertEquals(717.60, $rows['unallocated']);

        // a small payment is used up in order: all penalty, rest interest
        $small = $this->as('cashier')->getJson($url.'100')->json();
        $this->assertEquals([42.40, 57.60], array_column($small['rows'], 'amount'));
        $this->assertSame(0.0, (float) $small['unallocated']);

        // principal first
        Setting::put('allocation_preset', 'principal_first');
        $rows = $this->as('cashier')->getJson($url.'1000')->json()['rows'];
        $this->assertSame('loan_principal', $rows[0]['allocation_type']);
        $this->assertEquals(1000, $rows[0]['amount']);

        // custom order
        Setting::put('allocation_preset', 'custom');
        Setting::put('allocation_custom', 'interest,principal,penalty,fees,savings,share');
        $rows = $this->as('cashier')->getJson($url.'300')->json()['rows'];
        $this->assertSame(['loan_interest', 'loan_principal'], array_column($rows, 'allocation_type'));

        // single-purpose receipt types
        $one = $this->as('cashier')->getJson("/collections/suggest?member_id={$m->id}&type=savings_deposit&amount=250")->json()['rows'];
        $this->assertEquals([['allocation_type' => 'savings_deposit', 'loan_id' => null, 'loan_no' => null, 'amount' => 250, 'due' => null]], $one);

        // overview feeds the balance cards
        $o = $this->as('cashier')->getJson("/collections/member/{$m->id}")->assertOk()->json();
        $this->assertEquals(240, $o['totals']['interest_due']);
        $this->assertEquals(42.40, $o['totals']['penalty_due']);
        $this->assertEquals(4000 + 240 + 42.40, $o['totals']['total_due']);   // two installments (principal + interest) plus the penalty
        $this->assertEquals(4282.40, $o['loans'][0]['total_due']);
        $this->assertSame('PENALTY DUE', $o['member']['flag']);
        $this->assertEquals($loan->balance, $o['totals']['balance']);
    }

    // ---------- Posting ----------

    public function test_mixed_receipt_updates_every_account(): void
    {
        $m = $this->newMember();
        $loan = $this->overdueLoan($m);

        $lines = [
            ['allocation_type' => 'loan_penalty', 'loan_id' => $loan->id, 'amount' => 42.40],
            ['allocation_type' => 'loan_interest', 'loan_id' => $loan->id, 'amount' => 240],
            ['allocation_type' => 'loan_principal', 'loan_id' => $loan->id, 'amount' => 1000],
            ['allocation_type' => 'savings_deposit', 'amount' => 500],
            ['allocation_type' => 'share_contribution', 'amount' => 300],
            ['allocation_type' => 'membership_fee', 'amount' => 100],
            ['allocation_type' => 'insurance', 'amount' => 50],
        ];
        $total = 42.40 + 240 + 1000 + 500 + 300 + 100 + 50;     // 2,232.40

        $this->as('cashier')->post('/collections', $this->payload($m, $lines, ['cash_tendered' => 2300]))->assertSessionHasNoErrors();
        $c = Collection::latest('id')->first();

        $this->assertSame('posted', $c->status);
        $this->assertEqualsWithDelta($total, $c->total_amount, 0.001);
        $this->assertEquals(67.60, $c->change_due);                             // 2,300 given, 2,232.40 due
        $this->assertCount(7, $c->transactions);
        $this->assertStringStartsWith('OR-', $c->receipt_no);
        $this->assertSame("{$c->receipt_no}/1", $c->lines->first()->transaction->reference_no);   // traceable to the receipt

        $loan->refresh();
        $this->assertEquals(1000, $loan->principal_paid);
        $this->assertEquals(240, $loan->interest_paid);
        $this->assertEquals(42.40, $loan->penalty_paid);
        $this->assertEquals(12720 - 1240, $loan->balance);                      // a penalty does not reduce the loan itself
        $this->assertEquals(500, $m->savingsBalance());
        $this->assertEquals(300, $m->paidUpShares());
        $this->assertEquals(0, app(LoanScheduleService::class)->analyze($loan)['penalty']['due']);
        $this->assertSame('credit', 'credit');
        $this->balanced();
        $this->assertSame($m->id, $c->lines->first()->transaction->member_id);
        $this->assertNotNull($c->posted_at);
        $this->assertNotNull($c->collected_by);
    }

    public function test_amount_must_equal_the_breakdown(): void
    {
        $m = $this->newMember();
        $lines = [['allocation_type' => 'savings_deposit', 'amount' => 500]];

        $this->as('cashier')->post('/collections', $this->payload($m, $lines, ['total_amount' => 400]))->assertSessionHasErrors('total_amount');
        $this->assertSame(0, Transaction::where('type', 'savings_deposit')->where('member_id', $m->id)->count());   // nothing was posted
        $this->assertSame('draft', Collection::latest('id')->first()->status);                                     // but the draft is kept

        $this->as('cashier')->post('/collections', $this->payload($m, $lines, ['cash_tendered' => 100]))->assertSessionHasErrors('cash_tendered');
    }

    public function test_withdrawals_and_share_policy(): void
    {
        $m = $this->newMember();

        // not enough savings
        $this->as('cashier')->post('/collections', $this->payload($m, [['allocation_type' => 'savings_withdrawal', 'amount' => 100]]))->assertSessionHasErrors('lines');

        // a deposit on the same receipt can fund the withdrawal: pay 700 cash, 300 of savings goes with it
        $this->as('cashier')->post('/collections', $this->payload($m, [['allocation_type' => 'savings_deposit', 'amount' => 1000]]));
        $out = $this->payload($m, [['allocation_type' => 'savings_withdrawal', 'amount' => 400]], ['collection_type' => 'savings_withdrawal']);
        $this->as('cashier')->post('/collections', $out)->assertSessionHasNoErrors();
        $c = Collection::latest('id')->first();
        $this->assertSame('out', $c->direction);
        $this->assertEquals(400, $c->total_amount);
        $this->assertEquals(600, $m->savingsBalance());
        $this->assertNull($c->cash_tendered);                                  // nothing was received

        // share capital withdrawal follows the policy
        $this->as('cashier')->post('/collections', $this->payload($m, [['allocation_type' => 'share_contribution', 'amount' => 1000]]));
        $share = $this->payload($m, [['allocation_type' => 'share_withdrawal', 'amount' => 200]]);
        $this->as('cashier')->post('/collections', $share)->assertSessionHasErrors('lines');                 // default: not allowed

        Setting::put('share_withdrawal_policy', 'allowed');
        $this->as('cashier')->post('/collections', $share)->assertSessionHasNoErrors();
        $this->assertEquals(800, $m->paidUpShares());
        $this->as('cashier')->post('/collections', $this->payload($m, [['allocation_type' => 'share_withdrawal', 'amount' => 5000]]))->assertSessionHasErrors('lines');   // more than paid in

        Setting::put('share_withdrawal_policy', 'inactive_only');
        $this->as('cashier')->post('/collections', $share)->assertSessionHasErrors('lines');                 // member is active
        $m->update(['status' => 'inactive']);
        $this->as('cashier')->post('/collections', $share)->assertSessionHasNoErrors();
        $this->balanced();
    }

    public function test_loan_limits_per_part(): void
    {
        $m = $this->newMember();
        $loan = $this->overdueLoan($m);

        foreach ([['loan_principal', 13000], ['loan_interest', 721], ['loan_penalty', 50]] as [$type, $amount]) {
            $this->as('cashier')->post('/collections', $this->payload($m, [['allocation_type' => $type, 'loan_id' => $loan->id, 'amount' => $amount]]))->assertSessionHasErrors('lines');
        }
        // a loan row without a loan, and somebody else's loan
        $this->as('cashier')->post('/collections', $this->payload($m, [['allocation_type' => 'loan_principal', 'amount' => 10]]))->assertSessionHasErrors('lines');
        $other = $this->newMember();
        $this->as('cashier')->post('/collections', $this->payload($other, [['allocation_type' => 'loan_principal', 'loan_id' => $loan->id, 'amount' => 10]]))->assertSessionHasErrors('lines');
        $this->assertSame(0, Transaction::where('loan_id', $loan->id)->where('type', 'loan_principal')->count());
    }

    public function test_paying_off_a_loan_in_parts(): void
    {
        $m = $this->newMember();
        $loan = $this->overdueLoan($m, 0);

        $lines = [
            ['allocation_type' => 'loan_principal', 'loan_id' => $loan->id, 'amount' => 12000],
            ['allocation_type' => 'loan_interest', 'loan_id' => $loan->id, 'amount' => 720],
        ];
        $this->as('cashier')->post('/collections', $this->payload($m, $lines))->assertSessionHasNoErrors();

        $this->assertSame('paid', $loan->fresh()->status);
        $this->assertEquals(0, $loan->fresh()->balance);
        $this->balanced();
    }

    // ---------- Cancelling ----------

    public function test_cancelling_a_posted_receipt_reverses_everything(): void
    {
        $m = $this->newMember();
        $loan = $this->overdueLoan($m);
        $before = (float) $loan->balance;

        $lines = [
            ['allocation_type' => 'loan_principal', 'loan_id' => $loan->id, 'amount' => 2000],
            ['allocation_type' => 'loan_interest', 'loan_id' => $loan->id, 'amount' => 100],
            ['allocation_type' => 'savings_deposit', 'amount' => 400],
        ];
        $this->as('cashier')->post('/collections', $this->payload($m, $lines));
        $c = Collection::latest('id')->first();
        $this->assertEquals($before - 2100, $loan->fresh()->balance);

        // only roles with the reverse permission can cancel a posted receipt
        $this->as('cashier')->post("/collections/{$c->id}/cancel", ['reason' => 'wrong member'])->assertForbidden();
        $this->as('accountant')->post("/collections/{$c->id}/cancel", ['reason' => 'wrong member'])->assertSessionHasNoErrors();

        $c->refresh();
        $this->assertSame('cancelled', $c->status);
        $this->assertSame('wrong member', $c->cancel_reason);
        $this->assertNotNull($c->cancelled_at);
        $this->assertNotNull($c->cancelled_by);
        $this->assertStringStartsWith('OR-', $c->receipt_no);                    // the number stays
        $this->assertSame(0, $c->transactions()->where('status', 'completed')->count());
        $this->assertEquals($before, $loan->fresh()->balance);
        $this->assertEquals(0, $loan->fresh()->principal_paid);
        $this->assertEquals(0, $m->savingsBalance());
        $this->balanced();

        // it cannot be cancelled twice, and a line cannot be voided on its own
        $this->as('accountant')->post("/collections/{$c->id}/cancel", ['reason' => 'again'])->assertSessionHasErrors('collection');
        $this->as('accountant')->post('/collections', $this->payload($m, [['allocation_type' => 'savings_deposit', 'amount' => 10]]));
        $line = Transaction::whereNotNull('collection_id')->latest('id')->first();
        $this->as('accountant')->post("/transactions/{$line->id}/void", ['reason' => 'x'])->assertSessionHasErrors('transaction');
    }

    public function test_cancel_is_refused_when_it_would_break_the_books(): void
    {
        $m = $this->newMember();
        $this->as('cashier')->post('/collections', $this->payload($m, [['allocation_type' => 'savings_deposit', 'amount' => 500]]));
        $deposit = Collection::latest('id')->first();
        $this->as('cashier')->post('/collections', $this->payload($m, [['allocation_type' => 'savings_withdrawal', 'amount' => 500]]));

        $this->as('manager')->post("/collections/{$deposit->id}/cancel", ['reason' => 'oops'])->assertSessionHasErrors('collection');
        $this->assertSame('posted', $deposit->fresh()->status);
        $this->assertSame(1, $deposit->transactions()->where('status', 'completed')->count());
    }

    public function test_drafts(): void
    {
        $m = $this->newMember();
        $lines = [['allocation_type' => 'savings_deposit', 'amount' => 800]];

        // saving a draft posts nothing, and the draft can be edited and then posted
        $this->as('cashier')->post('/collections', $this->payload($m, $lines, ['action' => 'draft']))->assertSessionHasNoErrors();
        $draft = Collection::latest('id')->first();
        $this->assertSame('draft', $draft->status);
        $this->assertEquals(0, $m->savingsBalance());

        $edit = $this->payload($m, [['allocation_type' => 'savings_deposit', 'amount' => 900]], ['collection_id' => $draft->id]);
        $this->as('cashier')->post('/collections', $edit)->assertSessionHasNoErrors();
        $this->assertSame(1, Collection::count());
        $this->assertSame('posted', $draft->fresh()->status);
        $this->assertEquals(900, $m->savingsBalance());

        // a posted receipt cannot be edited
        $again = $this->payload($m, [['allocation_type' => 'savings_deposit', 'amount' => 1]], ['collection_id' => $draft->id, 'action' => 'draft']);
        $this->as('cashier')->post('/collections', $again)->assertSessionHasErrors('collection');
        $this->assertEquals(900, $draft->fresh()->total_amount);

        // a draft can be cancelled by the teller without a reversal
        $this->as('cashier')->post('/collections', $this->payload($m, $lines, ['action' => 'draft']));
        $d2 = Collection::latest('id')->first();
        $this->as('cashier')->post("/collections/{$d2->id}/cancel", ['reason' => 'changed mind'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $d2->fresh()->status);
    }

    public function test_receipt_numbers_and_validation(): void
    {
        $m = $this->newMember();
        $line = [['allocation_type' => 'savings_deposit', 'amount' => 10]];

        $this->as('cashier')->post('/collections', $this->payload($m, $line, ['receipt_no' => 'OR-MANUAL-1']))->assertSessionHasNoErrors();
        $this->assertSame('OR-MANUAL-1', Collection::latest('id')->first()->receipt_no);
        $this->as('cashier')->post('/collections', $this->payload($m, $line, ['receipt_no' => 'OR-MANUAL-1']))->assertSessionHasErrors('receipt_no');   // already used

        $this->as('cashier')->post('/collections', $this->payload($m, $line, ['collection_date' => now()->addDay()->toDateString()]))->assertSessionHasErrors('collection_date');
        $this->as('cashier')->post('/collections', $this->payload($m, [['allocation_type' => 'bribe', 'amount' => 10]]))->assertSessionHasErrors('lines.0.allocation_type');
        $this->as('cashier')->post('/collections', $this->payload($m, []))->assertSessionHasErrors('lines');
    }

    public function test_bank_methods_use_the_bank_account(): void
    {
        $m = $this->newMember();
        $this->as('cashier')->post('/collections', $this->payload($m, [['allocation_type' => 'savings_deposit', 'amount' => 700]], ['payment_method' => 'bank_transfer', 'payment_ref' => 'TRX-9']));

        $bank = Account::where('code', '1011')->first();
        $this->assertEquals(700, $bank->lines()->sum('debit'));
        $this->assertNull(Collection::latest('id')->first()->cash_tendered);       // change only applies to cash
    }

    public function test_roles_and_pages(): void
    {
        $m = $this->newMember();

        $this->as('staff')->get('/collections/new')->assertForbidden();
        $this->as('loan_officer')->post('/collections', $this->payload($m, [['allocation_type' => 'savings_deposit', 'amount' => 10]]))->assertForbidden();
        $this->as('auditor')->get('/collections')->assertOk();                     // can see the register
        $this->as('cashier')->get('/collections/new')->assertOk()->assertSee('Post Transaction');
        $this->as('cashier')->get('/collections')->assertOk();
        $this->as('cashier')->getJson('/collections/members?q=Lect')->assertOk();
    }
}
