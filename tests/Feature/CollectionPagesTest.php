<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\Setting;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\LoanService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionPagesTest extends TestCase
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

    private function member(): Member
    {
        return Member::create(['member_no' => 'MEM-P-'.random_int(1000, 9999), 'first_name' => 'Pat', 'last_name' => 'Pages', 'date_joined' => now(), 'status' => 'active', 'subscribed_shares' => 5000]);
    }

    private function overdueLoan(Member $member): Loan
    {
        $this->as('manager');
        $service = app(LoanService::class);
        $loan = $service->apply($member, LoanProduct::where('code', 'REG')->first(), 12000, 6, 'test');
        $service->approve($loan);
        $service->release($loan);
        $loan->update(['released_at' => now()->subMonths(2)->toDateString(), 'maturity_date' => now()->addMonths(4)->toDateString()]);

        return $loan->fresh();
    }

    private function receipt(Member $m, array $lines, array $over = []): Collection
    {
        $net = 0;
        foreach ($lines as $line) {
            $net += config("collections.allocations.{$line['allocation_type']}.direction") === 'out' ? -$line['amount'] : $line['amount'];
        }

        $this->as('cashier')->post('/collections', array_merge([
            'action' => 'post', 'member_id' => $m->id, 'collection_type' => 'mixed', 'collection_date' => now()->toDateString(),
            'payment_method' => 'cash', 'total_amount' => abs($net), 'cash_tendered' => abs($net) + 100, 'lines' => $lines,
        ], $over))->assertSessionHasNoErrors();

        return Collection::latest('id')->first();
    }

    public function test_receipt_register_workspace_and_receipt_pages(): void
    {
        $m = $this->member();
        $loan = $this->overdueLoan($m);
        $c = $this->receipt($m, [
            ['allocation_type' => 'loan_penalty', 'loan_id' => $loan->id, 'amount' => 42.40],
            ['allocation_type' => 'loan_interest', 'loan_id' => $loan->id, 'amount' => 240],
            ['allocation_type' => 'savings_deposit', 'amount' => 100],
        ]);

        // the saved receipt opens read-only in the workspace
        $this->as('cashier')->get("/collections/{$c->id}")->assertOk()->assertSee('POSTED')->assertSee($c->receipt_no)->assertSee('Reverse Transaction');
        $this->as('cashier')->get('/collections/new?member='.$m->id)->assertOk()->assertSee('Auto Allocate')->assertSee('Manual Allocation')->assertSee('Add Allocation Row')
            ->assertSee('Save as Draft')->assertSee('Post Transaction')->assertSee('Print Receipt');
        $this->as('cashier')->get("/collections/new?draft={$c->id}")->assertRedirect(route('collections.show', $c));   // posted: no editing

        // the printable receipt carries what the spec asks for
        $this->as('cashier')->get("/collections/{$c->id}/receipt")->assertOk()
            ->assertSee('Official receipt')->assertSee($c->receipt_no)->assertSee('Pat Pages')->assertSee('Loan Penalty')->assertSee('Loan Interest')
            ->assertSee('Regular Savings Deposit')->assertSee('Total amount received')->assertSee('Cash received')->assertSee('Change due')
            ->assertSee('Printed by')->assertSee('signature');
        $this->assertEquals(100, $c->fresh()->change_due);

        // the register and its filters
        $this->as('auditor')->get('/collections')->assertOk()->assertSee($c->receipt_no);
        $this->as('auditor')->get('/collections?status=cancelled')->assertOk()->assertDontSee($c->receipt_no);
        $this->as('auditor')->get('/collections?q=Pages&type=mixed&status=posted')->assertOk()->assertSee($c->receipt_no);

        // a cancelled receipt prints with its status and keeps the number
        $this->as('accountant')->post("/collections/{$c->id}/cancel", ['reason' => 'test']);
        $this->as('cashier')->get("/collections/{$c->id}/receipt")->assertOk()->assertSee('CANCELLED')->assertSee($c->receipt_no);
        $this->as('cashier')->get("/collections/{$c->id}")->assertOk()->assertSee('CANCELLED')->assertSee('test');
    }

    public function test_ledgers(): void
    {
        $m = $this->member();
        $loan = $this->overdueLoan($m);
        $this->receipt($m, [
            ['allocation_type' => 'loan_penalty', 'loan_id' => $loan->id, 'amount' => 10],
            ['allocation_type' => 'savings_deposit', 'amount' => 600],
            ['allocation_type' => 'share_contribution', 'amount' => 400],
        ]);

        // loan ledger: schedule, penalty due and the payments
        $this->as('loan_officer')->get("/loans/{$loan->id}/ledger")->assertOk()
            ->assertSee('Penalty due')->assertSee('Payment Schedule')->assertSee('Overdue')->assertSee('₱32.40')->assertSee('Payments and Charges');

        // member ledger: every account in one place
        $this->as('staff')->get("/members/{$m->id}/ledger")->assertOk()
            ->assertSee('Member / Client Ledger')->assertSee('Savings balance')->assertSee($loan->loan_no)->assertSee('OR-')->assertSee('₱600.00');
        $this->as('staff')->get("/members/{$m->id}/ledger?from=".now()->addDay()->toDateString())->assertOk()->assertSee('No transactions');

        // savings and share capital ledgers with running balances
        $this->as('staff')->get("/savings/{$m->id}/statement")->assertOk()->assertSee('₱600.00');
        $this->as('staff')->get("/share-capital/{$m->id}/ledger")->assertOk()->assertSee('Share Capital Ledger')->assertSee('₱400.00')->assertSee('Contribution');

        // the collection screen's data knows about the penalty
        $this->as('cashier')->getJson("/collections/member/{$m->id}")->assertJsonPath('totals.penalty_due', 32.4)->assertJsonPath('shares_paid', 400)->assertJsonPath('savings', 600);
    }

    public function test_collection_reports(): void
    {
        $m = $this->member();
        $loan = $this->overdueLoan($m);
        $first = $this->receipt($m, [
            ['allocation_type' => 'loan_penalty', 'loan_id' => $loan->id, 'amount' => 42.40],
            ['allocation_type' => 'savings_deposit', 'amount' => 500],
        ]);
        $second = $this->receipt($m, [['allocation_type' => 'savings_deposit', 'amount' => 70]]);
        $this->receipt($m, [['allocation_type' => 'savings_withdrawal', 'amount' => 100]], ['collection_type' => 'savings_withdrawal']);
        $this->as('accountant')->post("/collections/{$second->id}/cancel", ['reason' => 'duplicate entry']);

        $report = fn (string $name) => $this->as('accountant')->get("/reports?report=$name")->assertOk();

        $report('daily_collection')->assertSee($first->receipt_no)->assertSee('₱542.40')->assertSee('Paid out');
        $report('collection_by_type')->assertSee('Loan Penalty')->assertSee('Regular Savings Withdrawal')->assertSee('TOTAL received');
        $report('cashier_summary')->assertSee('Maria Santos')->assertSee('Net cash');
        $report('penalty_collection')->assertSee($first->receipt_no)->assertSee('₱42.40');
        $report('cancelled_receipts')->assertSee($second->receipt_no)->assertSee('duplicate entry')->assertSee('Posted, then reversed');

        // each can be exported
        foreach (['daily_collection', 'collection_by_type', 'cashier_summary', 'penalty_collection', 'cancelled_receipts'] as $name) {
            $this->as('accountant')->get("/reports/export?report=$name")->assertOk();
        }
        // the cancelled one no longer counts as collected
        $report('daily_collection')->assertDontSee($second->receipt_no);
    }

    public function test_settings_for_priority_and_share_policy(): void
    {
        $base = ['coop_name' => 'X', 'branch_name' => 'Y', 'default_interest' => 12, 'par_threshold' => 5, 'collection_target' => 95, 'min_balance' => 0,
            'allocation_preset' => 'custom', 'allocation_custom' => ['principal', 'fees', 'interest', 'penalty', 'savings', 'share'],
            'share_withdrawal_policy' => 'inactive_only', 'regular_savings' => 0, 'regular_share' => 0];

        $this->as('super_admin')->put('/settings', $base)->assertSessionHasNoErrors();
        $this->assertSame(['principal', 'fees', 'interest', 'penalty', 'savings', 'share'], app(CollectionService::class)->priority());

        $this->as('super_admin')->put('/settings', array_merge($base, ['allocation_preset' => 'interest_first']));
        $this->assertSame('interest', app(CollectionService::class)->priority()[0]);

        $this->as('super_admin')->put('/settings', array_merge($base, ['allocation_preset' => 'nonsense']))->assertSessionHasErrors('allocation_preset');
        $this->as('super_admin')->put('/settings', array_merge($base, ['share_withdrawal_policy' => 'anything']))->assertSessionHasErrors('share_withdrawal_policy');
        $this->as('cashier')->put('/settings', $base)->assertForbidden();

        $this->as('super_admin')->get('/settings')->assertOk()->assertSee('allocation priority')->assertSee('Penalty first')->assertSee('Custom order');
    }

    public function test_regular_savings_and_share_in_the_allocation_order(): void
    {
        $m = $this->member();
        Setting::put('regular_savings', '200');
        Setting::put('regular_share', '150');

        // with no loan, the priority sets aside the regular amounts and the rest stays unallocated
        $r = $this->as('cashier')->getJson("/collections/suggest?member_id={$m->id}&type=mixed&amount=1000")->json();
        $this->assertSame(['savings_deposit', 'share_contribution'], array_column($r['rows'], 'allocation_type'));
        $this->assertEquals([200, 150], array_column($r['rows'], 'amount'));
        $this->assertEquals(650, $r['unallocated']);

        // penalty collection only looks at penalties
        $loan = $this->overdueLoan($m);
        $p = $this->as('cashier')->getJson("/collections/suggest?member_id={$m->id}&type=penalty&amount=500")->json();
        $this->assertSame(['loan_penalty'], array_column($p['rows'], 'allocation_type'));
        $this->assertEquals(42.40, $p['rows'][0]['amount']);
    }

    public function test_product_form_and_computation_show_the_new_lines(): void
    {
        $emg = LoanProduct::where('code', 'EMG')->first();
        $this->as('manager')->get("/loan-products/{$emg->id}?amount=6000&term=6")->assertOk()->assertSee('Ending Loan Balance')->assertSee('Net Proceeds');
        $this->as('manager')->get('/loan-products/create')->assertOk()->assertSee('Calamity Loan')->assertSee('Motorcycle Loan');
    }
}
