<?php

namespace Tests\Feature;

use App\Models\JournalLine;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LoanCalculator;
use App\Support\Tenant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanProductTest extends TestCase
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

    /** A product with sensible defaults; override anything. */
    private function product(array $over = [], array $deductions = []): LoanProduct
    {
        $product = LoanProduct::create(array_merge([
            'code' => 'T'.random_int(1000, 9999), 'name' => 'Test Product', 'interest_rate' => 10,
            'min_amount' => 1000, 'max_amount' => 100000, 'min_term' => 1, 'max_term' => 36,
            'term_unit' => 'months', 'payment_frequency' => 'monthly', 'interest_type' => 'flat',
            'interest_basis' => 'annual', 'amortization_method' => 'equal_payment', 'is_active' => true,
        ], $over));

        foreach ($deductions as [$name, $type, $value, $timing]) {
            $product->deductions()->create(['name' => $name, 'type' => $type, 'value' => $value, 'timing' => $timing]);
        }

        return $product->load('deductions');
    }

    private function calc(LoanProduct $p, float $amount, int $term, ?string $freq = null): array
    {
        return app(LoanCalculator::class)->compute($p, $amount, $term, $freq);
    }

    /** A complete, valid form submission. */
    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'Form Product', 'code' => 'FRM-1', 'category' => 'Personal', 'description' => 'x', 'is_active' => 1,
            'min_amount' => 1000, 'max_amount' => 50000, 'min_term' => 1, 'max_term' => 12, 'term_unit' => 'months',
            'payment_frequency' => 'monthly', 'interest_type' => 'flat', 'interest_rate' => 12, 'interest_basis' => 'annual',
            'amortization_method' => 'equal_payment', 'auto_schedule' => 1,
            'penalty_type' => 'none', 'penalty_value' => 0, 'penalty_basis' => 'per_day', 'grace_days' => 0,
            'min_savings' => 0, 'min_shares' => 0, 'guarantor_required' => 0, 'guarantors_required' => 0,
            'approval_level' => 'manager', 'release_method' => 'cash',
            'allow_early_payment' => 1, 'early_payment_penalty' => 0, 'early_payment_penalty_rate' => 0,
            'allow_renewal' => 0, 'allow_restructuring' => 0,
        ], $over);
    }

    // ---------- Computation ----------

    public function test_flat_interest(): void
    {
        $r = $this->calc($this->product(), 12000, 6);

        $this->assertEquals(600, $r['interest']);
        $this->assertEquals(12600, $r['total_payable']);
        $this->assertEquals(2100, $r['payment_amount']);
        $this->assertSame(6, $r['payments']);
        $this->assertEquals(12000, $r['net_proceeds']);
    }

    public function test_deductions_net_proceeds_and_fees(): void
    {
        $p = $this->product([], [
            ['Service Fee', 'percentage', 2, 'upon_release'],      // 200
            ['Processing Fee', 'fixed', 100, 'upon_release'],      // 100
            ['Insurance Fee', 'fixed', 250, 'add_to_payable'],     // paid back with the loan, spread evenly
            ['Filing Fee', 'fixed', 120, 'first_payment'],         // first payment only
            ['Unused', 'none', 0, 'upon_release'],
        ]);
        $r = $this->calc($p, 10000, 5);   // 10% for 5 months = 416.67 interest

        $this->assertEquals(300, $r['total_deductions']);
        $this->assertEquals(9700, $r['net_proceeds']);
        $this->assertEquals(370, $r['fees_payable']);
        $this->assertEquals(10000 + $r['interest'] + 370, $r['total_payable']);
        $this->assertCount(5, $r['deductions']);

        // the first payment carries the filing fee, the others do not
        $this->assertEquals($r['rows'][1]['payment'] + 120, $r['first_payment']);
        $this->assertEqualsWithDelta($r['total_payable'], array_sum(array_column($r['rows'], 'payment')), 0.001);
    }

    public function test_inactive_deductions_are_ignored(): void
    {
        $p = $this->product([], [['Fee', 'fixed', 500, 'upon_release']]);
        $p->deductions()->update(['is_active' => false]);

        $this->assertEquals(0, $this->calc($p->load('deductions'), 10000, 6)['total_deductions']);
    }

    public function test_diminishing_balance(): void
    {
        $r = $this->calc($this->product(['interest_type' => 'diminishing', 'interest_rate' => 12]), 12000, 12);

        $this->assertEquals(1066.19, $r['payment_amount']);                                  // standard loan payment formula
        $this->assertEquals(12000, array_sum(array_column($r['rows'], 'principal')));
        $this->assertEquals(0, end($r['rows'])['balance']);
        $this->assertEqualsWithDelta(794.28, $r['interest'], 0.1);                           // less than flat interest (1,440)
    }

    public function test_diminishing_equal_principal(): void
    {
        $r = $this->calc($this->product(['interest_type' => 'diminishing', 'amortization_method' => 'equal_principal', 'interest_rate' => 12]), 12000, 12);

        $this->assertEquals(1000, $r['rows'][0]['principal']);
        $this->assertEquals(120, $r['rows'][0]['interest']);                                 // 12000 x 1%
        $this->assertTrue($r['payments_vary']);                                              // payments get smaller
        $this->assertGreaterThan($r['rows'][11]['payment'], $r['rows'][0]['payment']);
    }

    public function test_compound_and_interest_free_and_per_term(): void
    {
        $compound = $this->calc($this->product(['interest_type' => 'compound', 'interest_rate' => 12]), 12000, 12);
        $this->assertEqualsWithDelta(1521.90, $compound['interest'], 0.01);                  // 12000 x 1.01^12 - 12000 = 1,521.90

        $free = $this->calc($this->product(['interest_type' => 'interest_free', 'interest_rate' => 0]), 12000, 12);
        $this->assertEquals(0, $free['interest']);
        $this->assertEquals(12000, $free['total_payable']);

        $perTerm = $this->calc($this->product(['interest_basis' => 'per_term', 'interest_rate' => 5]), 10000, 6);
        $this->assertEquals(500, $perTerm['interest']);                                      // 5% of the amount for the whole term
    }

    public function test_term_units_and_frequencies(): void
    {
        $weekly = $this->calc($this->product(['term_unit' => 'weeks', 'payment_frequency' => 'weekly']), 5200, 26);
        $this->assertSame(26, $weekly['payments']);

        $oneTime = $this->calc($this->product(['term_unit' => 'days']), 10000, 30, 'one_time');
        $this->assertSame(1, $oneTime['payments']);

        $years = $this->calc($this->product(['term_unit' => 'years', 'payment_frequency' => 'quarterly']), 10000, 2);
        $this->assertSame(8, $years['payments']);

        $semi = $this->calc($this->product(['payment_frequency' => 'semi_monthly']), 12000, 6);
        $this->assertSame(12, $semi['payments']);
    }

    // ---------- Form rules ----------

    public function test_create_edit_and_form_rules(): void
    {
        $payload = $this->payload(['deductions' => [
            ['name' => 'Service Fee', 'type' => 'percentage', 'value' => 2, 'basis' => 'loan_amount', 'timing' => 'upon_release', 'is_required' => 1, 'is_editable' => 0, 'is_active' => 1],
            ['name' => 'Other', 'type' => 'none', 'value' => 99, 'basis' => 'per_transaction', 'timing' => 'add_to_payable', 'is_required' => 0, 'is_editable' => 1, 'is_active' => 1],
        ], 'required_documents' => ['valid_id', 'address_proof']]);

        $this->as('manager')->post('/loan-products', $payload)->assertSessionHasNoErrors();
        $product = LoanProduct::where('code', 'FRM-1')->firstOrFail();

        $this->assertCount(2, $product->deductions);                                          // several deductions per product
        $this->assertEquals(0, $product->deductions[1]->value);                               // type None forces value 0
        $this->assertSame(['valid_id', 'address_proof'], $product->required_documents);

        $this->as('manager')->get("/loan-products/{$product->id}")->assertOk()->assertSee('Net Proceeds')->assertSee('Service Fee');
        $this->as('manager')->get("/loan-products/{$product->id}/edit")->assertOk();

        // minimum must not be above maximum
        $this->as('manager')->post('/loan-products', $this->payload(['code' => 'X1', 'min_amount' => 9000, 'max_amount' => 1000]))->assertSessionHasErrors('max_amount');
        $this->as('manager')->post('/loan-products', $this->payload(['code' => 'X2', 'min_term' => 12, 'max_term' => 3]))->assertSessionHasErrors('max_term');

        // interest-free forces rate 0
        $this->as('manager')->post('/loan-products', $this->payload(['code' => 'X3', 'interest_type' => 'interest_free', 'interest_rate' => 15]))->assertSessionHasNoErrors();
        $this->assertEquals(0, LoanProduct::where('code', 'X3')->value('interest_rate'));

        // guarantor not required forces the number to 0; required needs at least 1
        $this->as('manager')->post('/loan-products', $this->payload(['code' => 'X4', 'guarantor_required' => 0, 'guarantors_required' => 3]));
        $this->assertEquals(0, LoanProduct::where('code', 'X4')->value('guarantors_required'));
        $this->as('manager')->post('/loan-products', $this->payload(['code' => 'X5', 'guarantor_required' => 1, 'guarantors_required' => 0]))->assertSessionHasErrors('guarantors_required');

        // no early payment => no early payment penalty; penalty "No" => no rate
        $this->as('manager')->post('/loan-products', $this->payload(['code' => 'X6', 'allow_early_payment' => 0, 'early_payment_penalty' => 1, 'early_payment_penalty_rate' => 5]));
        $x6 = LoanProduct::where('code', 'X6')->first();
        $this->assertFalse($x6->early_payment_penalty);
        $this->assertEquals(0, $x6->early_payment_penalty_rate);

        // penalty type None forces value 0; unknown choices are rejected
        $this->as('manager')->post('/loan-products', $this->payload(['code' => 'X7', 'penalty_type' => 'none', 'penalty_value' => 9]));
        $this->assertEquals(0, LoanProduct::where('code', 'X7')->value('penalty_value'));
        $this->as('manager')->post('/loan-products', $this->payload(['code' => 'X8', 'term_unit' => 'decades']))->assertSessionHasErrors('term_unit');

        // editing replaces the deductions
        $this->as('manager')->put("/loan-products/{$product->id}", $this->payload(['deductions' => [
            ['name' => 'Only One', 'type' => 'fixed', 'value' => 50, 'basis' => 'loan_amount', 'timing' => 'upon_release', 'is_required' => 1, 'is_editable' => 0, 'is_active' => 1],
        ]]))->assertSessionHasNoErrors();
        $this->assertSame(['Only One'], $product->fresh()->deductions->pluck('name')->all());
    }

    public function test_delete_only_without_loans_and_toggle(): void
    {
        $used = LoanProduct::where('code', 'REG')->first();   // has seeded loans
        $unused = $this->product(['code' => 'ZZZ']);

        $this->as('manager')->delete("/loan-products/{$used->id}")->assertSessionHasErrors('product');
        $this->assertNotNull($used->fresh());

        $this->as('manager')->delete("/loan-products/{$unused->id}")->assertRedirect('/loan-products');
        $this->assertNull(LoanProduct::find($unused->id));

        $this->as('manager')->post("/loan-products/{$used->id}/toggle");
        $this->assertFalse($used->fresh()->is_active);

        $this->as('cashier')->delete("/loan-products/{$used->id}")->assertForbidden();
    }

    public function test_list_search_and_filters(): void
    {
        $this->as('manager')->get('/loan-products?q=Emergency')->assertOk()->assertSee('EMG')->assertDontSee('BUS');
        $this->as('manager')->get('/loan-products?category=Business Loan')->assertOk()->assertSee('BUS')->assertDontSee('EMG');
        LoanProduct::where('code', 'EDU')->update(['is_active' => false]);
        $this->as('manager')->get('/loan-products?status=inactive')->assertOk()->assertSee('EDU')->assertDontSee('REG');
    }

    public function test_sample_computation_tool(): void
    {
        $p = LoanProduct::where('code', 'REG')->first();   // 2% + 100 at release, 12%/yr

        $this->as('manager')->get("/loan-products/{$p->id}?amount=10000&term=6&frequency=monthly")
            ->assertOk()->assertSee('Less: Total Deductions')->assertSee('₱9,700.00')->assertSee('₱10,600.00');

        $this->as('manager')->get("/loan-products/{$p->id}?amount=999999&term=6")->assertOk()->assertSee('outside the product limits');
        $this->as('manager')->get("/loan-products/{$p->id}/compute?amount=10000&term=6")->assertOk()->assertJsonPath('net_proceeds', 9700);
    }

    // ---------- Applying and releasing with a product ----------

    public function test_loan_with_deductions_end_to_end(): void
    {
        $member = Member::where('status', 'active')->first();
        $reg = LoanProduct::where('code', 'REG')->first();

        // limits come from the product
        $this->as('loan_officer')->post('/loans', ['member_id' => $member->id, 'loan_product_id' => $reg->id, 'principal' => 999999, 'term' => 6])->assertSessionHasErrors('principal');
        $this->as('loan_officer')->post('/loans', ['member_id' => $member->id, 'loan_product_id' => $reg->id, 'principal' => 10000, 'term' => 60])->assertSessionHasErrors('term');

        $this->as('loan_officer')->post('/loans', ['member_id' => $member->id, 'loan_product_id' => $reg->id, 'principal' => 10000, 'term' => 6])->assertSessionHasNoErrors();
        $loan = Loan::latest('id')->first();

        $this->assertEquals(9700, $loan->net_proceeds);          // 10,000 - 2% - 100
        $this->assertEquals(300, $loan->deductions_total);
        $this->assertEquals(10600, $loan->total_payable);        // 12% for half a year
        $this->assertSame(6, $loan->number_of_payments);
        $this->assertCount(6, $loan->schedule);

        $this->as('manager')->post("/loans/{$loan->id}/approve");
        $this->as('cashier')->post("/loans/{$loan->id}/release")->assertSessionHasNoErrors();

        $loan->refresh();
        $this->assertSame('released', $loan->status);
        $this->assertEquals(10600, $loan->balance);
        $this->assertEquals(9700, Transaction::where('loan_id', $loan->id)->where('type', 'loan_release')->value('amount'));   // cash handed over

        $this->as('super_admin')->get("/loans/{$loan->id}")->assertOk()->assertSee('Net proceeds');

        // the loan can be paid off and the books stay balanced
        $this->as('cashier')->post('/cash-receipts', ['type' => 'loan_payment', 'member_id' => $member->id, 'loan_id' => $loan->id, 'amount' => 10600])->assertSessionHasNoErrors();
        $this->assertSame('paid', $loan->fresh()->status);
        $this->assertEqualsWithDelta(JournalLine::sum('debit'), JournalLine::sum('credit'), 0.001);
    }

    public function test_added_fees_are_repaid_and_books_balance(): void
    {
        $member = Member::where('status', 'active')->where('id', '!=', 0)->orderBy('id')->skip(3)->first();   // enough savings for the business product
        $bus = LoanProduct::where('code', 'BUS')->first();                                                      // 1.5% at release + 250 added to payable

        $this->as('loan_officer')->post('/loans', ['member_id' => $member->id, 'loan_product_id' => $bus->id, 'principal' => 40000, 'term' => 12])->assertSessionHasNoErrors();
        $loan = Loan::latest('id')->first();

        $this->assertEquals(600, $loan->deductions_total);        // 1.5% of 40,000
        $this->assertEquals(39400, $loan->net_proceeds);
        $this->assertEquals(250, $loan->fees_added);

        $this->as('manager')->post("/loans/{$loan->id}/approve");
        $this->as('cashier')->post("/loans/{$loan->id}/release");
        $this->as('cashier')->post('/cash-receipts', ['type' => 'loan_payment', 'member_id' => $member->id, 'loan_id' => $loan->id, 'amount' => $loan->fresh()->balance])->assertSessionHasNoErrors();

        $this->assertSame('paid', $loan->fresh()->status);
        $this->assertEqualsWithDelta(JournalLine::sum('debit'), JournalLine::sum('credit'), 0.001);
    }

    public function test_product_requirements_are_enforced(): void
    {
        $bus = LoanProduct::where('code', 'BUS')->first();
        $bus->update(['min_shares' => 5000000]);
        $member = Member::where('status', 'active')->orderBy('id')->skip(3)->first();

        $this->as('loan_officer')->post('/loans', ['member_id' => $member->id, 'loan_product_id' => $bus->id, 'principal' => 40000, 'term' => 12])->assertSessionHasErrors('member_id');

        $bus->update(['min_shares' => 0, 'min_savings' => 99999999]);
        $this->as('loan_officer')->post('/loans', ['member_id' => $member->id, 'loan_product_id' => $bus->id, 'principal' => 40000, 'term' => 12])->assertSessionHasErrors('member_id');
    }

    public function test_inactive_product_cannot_be_used_and_application_form_loads(): void
    {
        $emg = LoanProduct::where('code', 'EMG')->first();
        $member = Member::where('status', 'active')->first();

        $this->as('loan_officer')->get('/loans/create')->assertOk()->assertSee('Computation Summary');

        $this->as('manager')->post("/loan-products/{$emg->id}/toggle");
        $this->as('loan_officer')->post('/loans', ['member_id' => $member->id, 'loan_product_id' => $emg->id, 'principal' => 5000, 'term' => 3])->assertSessionHasErrors('loan_product_id');
        $this->as('loan_officer')->post("/loan-products/{$emg->id}/toggle")->assertForbidden();
    }
}
