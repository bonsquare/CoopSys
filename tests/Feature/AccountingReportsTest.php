<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\Setting;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\FinancialReportService;
use App\Services\LoanService;
use App\Services\PeriodClosingService;
use App\Services\PortfolioReportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountingReportsTest extends TestCase
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

    private function acct(string $code): Account
    {
        return Account::byCode($code);
    }

    private function reports(): FinancialReportService
    {
        return app(FinancialReportService::class);
    }

    private function books(): AccountingService
    {
        return app(AccountingService::class);
    }

    /** Post a balanced manual entry: [[account code, debit, credit], ...] */
    private function entry(string $date, array $lines, string $description = 'Test'): JournalEntry
    {
        $this->as('accountant')->post('/accounting/journal', [
            'entry_date' => $date, 'description' => $description,
            'lines' => array_map(fn ($l) => ['account_id' => $this->acct($l[0])->id, 'debit' => $l[1] ?: '', 'credit' => $l[2] ?: ''], $lines),
        ])->assertSessionHasNoErrors();

        return JournalEntry::latest('id')->first();
    }

    // ---------- Periods ----------

    public function test_comparison_periods(): void
    {
        $r = $this->reports();

        $this->assertSame(['2026-09-01', '2026-09-30'], $r->comparisonRange('2026-10-01', '2026-10-31', 'previous'));            // a month
        $this->assertSame(['2026-04-01', '2026-06-30'], $r->comparisonRange('2026-07-01', '2026-09-30', 'previous'));            // a quarter
        $this->assertSame(['2025-01-01', '2025-12-31'], $r->comparisonRange('2026-01-01', '2026-12-31', 'previous'));            // a year
        $this->assertSame(['2026-10-01', '2026-10-10'], $r->comparisonRange('2026-10-11', '2026-10-20', 'previous'));            // ten days
        $this->assertSame(['2025-03-05', '2025-04-20'], $r->comparisonRange('2026-03-05', '2026-04-20', 'lastyear'));
        $this->assertNull($r->comparisonRange('2026-01-01', '2026-01-31', 'none'));
    }

    // ---------- Income statement and balance sheet ----------

    public function test_income_statement_compares_two_periods_in_groups(): void
    {
        // an account of its own, so the seeded demo data does not get in the way
        $this->as('accountant')->post('/accounting/accounts', ['code' => '4090', 'name' => 'Training Income', 'type' => 'income', 'report_group' => 'Other Services'])->assertSessionHasNoErrors();
        $this->entry(now()->startOfMonth()->addDays(2)->toDateString(), [['1010', 3000, 0], ['4090', 0, 3000]]);
        $this->entry(now()->subMonthNoOverflow()->startOfMonth()->addDays(4)->toDateString(), [['1010', 2000, 0], ['4090', 0, 2000]]);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();
        $is = $this->reports()->incomeStatement($from, $to, $this->reports()->comparisonRange($from, $to, 'previous'));

        $group = collect($is['sections']['income'])->firstWhere('name', 'Other Services');
        $this->assertNotNull($group);                                      // lines are grouped under their report group
        $line = $group['lines'][0];
        $this->assertEquals(3000, $line['current']);
        $this->assertEquals(2000, $line['previous']);
        $this->assertEquals(1000, $line['change']);
        $this->assertEquals(50.0, $line['pct']);
        $this->assertEquals($is['total_income'] - $is['prev_income'], $is['income_change']);
        $this->assertEquals($is['net'] - $is['prev_net'], $is['net_change']);

        // the page shows the comparison, and without one it shows the share of income
        $this->as('accountant')->get("/accounting/income-statement?from=$from&to=$to&compare=previous")->assertOk()->assertSee('Other Services')->assertSee('+50%')->assertSee('Change');
        $this->as('accountant')->get("/accounting/income-statement?from=$from&to=$to")->assertOk()->assertSee('% of income');
        $this->as('accountant')->get("/accounting/income-statement?from=$from&to=$to&compare=lastyear")->assertOk();

        $csv = $this->as('accountant')->get("/accounting/income-statement?from=$from&to=$to&compare=previous&export=csv")->assertOk();
        $this->assertStringContainsString('Change %', $csv->streamedContent());
        $this->assertStringContainsString('Training Income', $csv->streamedContent());
    }

    public function test_balance_sheet_compares_two_dates(): void
    {
        $lastMonthEnd = now()->subMonthNoOverflow()->endOfMonth()->toDateString();
        $this->entry(now()->subMonthNoOverflow()->startOfMonth()->addDays(3)->toDateString(), [['1011', 8000, 0], ['3020', 0, 8000]], 'Opening bank balance');
        $this->entry(now()->toDateString(), [['1011', 500, 0], ['3020', 0, 500]], 'Later top-up');

        $sheet = $this->reports()->balanceSheet(now()->toDateString(), $lastMonthEnd);
        $bank = fn ($set) => collect($set)->flatMap(fn ($g) => $g['lines'])->first(fn ($l) => $l['account']->code === '1011');

        $this->assertEquals(8500, $bank($sheet['sections']['asset'])['current']);
        $this->assertEquals(8000, $bank($sheet['sections']['asset'])['previous']);
        $this->assertEquals(500, $bank($sheet['sections']['asset'])['change']);
        $this->assertTrue($sheet['current']['balanced']);
        $this->assertTrue($sheet['previous']['balanced']);
        $this->assertEqualsWithDelta($sheet['current']['total_assets'] - $sheet['previous']['total_assets'], $sheet['changes']['assets'], 0.005);

        foreach (['lastmonth', 'yearend', 'lastyear', 'none'] as $compare) {
            $this->as('accountant')->get("/accounting/balance-sheet?compare=$compare")->assertOk()->assertSee('TOTAL LIABILITIES AND EQUITY');
            $this->as('accountant')->get("/accounting/balance-sheet?compare=$compare&export=csv")->assertOk();
        }
        $this->as('accountant')->get('/accounting/balance-sheet?compare=lastmonth')->assertSee('Change');
    }

    public function test_report_groups_can_be_changed_and_default_when_empty(): void
    {
        $rent = $this->acct('5030');
        $this->assertSame('Operating Expenses', $rent->groupName());

        $this->as('accountant')->put("/accounting/accounts/{$rent->id}", ['code' => '5030', 'name' => 'Rent and Utilities', 'type' => 'expense', 'report_group' => 'Premises'])->assertSessionHasNoErrors();
        $this->assertSame('Premises', $rent->fresh()->groupName());

        $this->as('accountant')->put("/accounting/accounts/{$rent->id}", ['code' => '5030', 'name' => 'Rent and Utilities', 'type' => 'expense', 'report_group' => ''])->assertSessionHasNoErrors();
        $this->assertSame('Other Expenses', $rent->fresh()->groupName());   // the default for the type
        $this->as('accountant')->get('/accounting/accounts')->assertOk()->assertSee('Report group');
    }

    // ---------- Cash flow ----------

    public function test_cash_flow_agrees_with_the_cash_accounts(): void
    {
        $from = now()->startOfYear()->toDateString();
        $to = now()->toDateString();

        // add a variety of manual cash movements: a bank charge, an equipment purchase, a bank loan received, and a cash-to-bank transfer
        $this->entry(now()->toDateString(), [['5040', 120, 0], ['1011', 0, 120]], 'Bank charge');
        $this->entry(now()->toDateString(), [['1040', 4000, 0], ['1010', 0, 4000]], 'Bought a computer');
        $this->entry(now()->toDateString(), [['1011', 9000, 0], ['2030', 0, 9000]], 'Supplier credit paid to our bank');
        $this->entry(now()->toDateString(), [['1011', 700, 0], ['1010', 0, 700]], 'Cash deposited in the bank');

        $cf = $this->reports()->cashFlow($from, $to);
        $cashNow = collect($this->books()->trialBalance($to)['rows'])->filter(fn ($r) => in_array($r['account']->code, ['1010', '1011']))->sum(fn ($r) => $r['tb_debit'] - $r['tb_credit']);

        $this->assertTrue($cf['agrees']);
        $this->assertEqualsWithDelta($cashNow, $cf['closing'], 0.005);
        $this->assertEqualsWithDelta($cf['opening'] + $cf['net'], $cf['closing'], 0.005);
        $this->assertEqualsWithDelta($cf['totals']['operating'] + $cf['totals']['investing'] + $cf['totals']['financing'], $cf['net'], 0.005);

        $find = fn ($section, $code) => collect($cf['sections'][$section])->first(fn ($l) => $l['account']->code === $code);
        $this->assertNotNull($find('investing', '1040'));                  // equipment is investing
        $this->assertEquals(-4000, $find('investing', '1040')['amount']);
        $this->assertNotNull($find('investing', '1020'));                  // loans released and collected
        $this->assertNotNull($find('financing', '2010'));                  // member deposits
        $this->assertNotNull($find('financing', '3010'));                  // share capital
        $this->assertGreaterThan(0, $find('operating', '4010')['amount']); // interest collected
        $this->assertEquals(-120, $find('operating', '5040')['amount']);   // bank charges
        $this->assertEquals(9000, $find('operating', '2030')['amount']);   // payables are operating
        $this->assertNull($find('operating', '1011'));                     // a transfer between cash and bank is not a cash flow

        $this->as('accountant')->get("/accounting/cash-flow?from=$from&to=$to")->assertOk()->assertSee('OPERATING ACTIVITIES')->assertSee('INVESTING ACTIVITIES')->assertSee('FINANCING ACTIVITIES')->assertSee('agrees with the cash and bank');
        $this->as('accountant')->get("/accounting/cash-flow?from=$from&to=$to&export=csv")->assertOk();
    }

    public function test_cash_group_decides_what_counts_as_cash(): void
    {
        // a new bank account in the "Cash and Bank" group is cash too
        $this->as('accountant')->post('/accounting/accounts', ['code' => '1012', 'name' => 'Savings Account at Another Bank', 'type' => 'asset', 'report_group' => 'Cash and Bank']);
        $this->entry(now()->toDateString(), [['1012', 2500, 0], ['4020', 0, 2500]], 'Fee paid into the other bank');

        $cf = $this->reports()->cashFlow(now()->startOfYear()->toDateString(), now()->toDateString());
        $this->assertTrue($cf['agrees']);
        $this->assertContains($this->acct('1012')->id, $this->reports()->cashAccountIds());
    }

    // ---------- Changes in equity ----------

    public function test_changes_in_equity_agree_with_the_balance_sheet(): void
    {
        $from = now()->startOfYear()->toDateString();
        $to = now()->toDateString();
        $this->entry($to, [['1011', 6000, 0], ['3020', 0, 6000]], 'Reserve funded');

        $eq = $this->reports()->equityChanges($from, $to);
        $this->assertTrue($eq['agrees']);
        $this->assertEqualsWithDelta($this->books()->balanceSheet($to)['total_equity'], $eq['closing_total'], 0.005);

        $reserve = collect($eq['rows'])->firstWhere('label', 'Retained Surplus');
        $this->assertEquals(6000, $reserve['added']);
        $this->assertEquals(6000, $reserve['closing']);
        $this->assertEqualsWithDelta($this->books()->incomeStatement($from, $to)['net'], $eq['surplus']['net'], 0.005);

        $shares = collect($eq['rows'])->firstWhere('label', 'Share Capital - Paid-up');
        $this->assertEquals(0, $shares['opening']);
        $this->assertGreaterThan(0, $shares['added']);

        $this->as('accountant')->get("/accounting/changes-in-equity?from=$from&to=$to")->assertOk()->assertSee('Statement of Changes in Equity')->assertSee('agrees with the balance sheet');
        $this->as('accountant')->get("/accounting/changes-in-equity?from=$from&to=$to&export=csv")->assertOk();
    }

    // ---------- Highlights ----------

    public function test_ratios_and_trend(): void
    {
        $bs = $this->books()->balanceSheet(now()->toDateString());
        $is = $this->books()->incomeStatement(now()->startOfYear()->toDateString(), now()->toDateString());
        $ratios = collect($this->reports()->ratios())->keyBy('label');

        $this->assertEqualsWithDelta(round($is['total_expenses'] / $is['total_income'] * 100, 1), $ratios['Cost-to-income ratio']['value'], 0.05);
        $this->assertEqualsWithDelta(round($is['net'] / $is['total_income'] * 100, 1), $ratios['Net surplus margin']['value'], 0.05);
        $this->assertEqualsWithDelta(round($bs['total_equity'] / $bs['total_assets'] * 100, 1), $ratios['Capital-to-assets']['value'], 0.05);
        $this->assertEqualsWithDelta($bs['total_equity'], $ratios['Total equity']['value'], 0.005);
        $this->assertSame('amount', $ratios['Total equity']['kind']);
        $this->assertCount(12, collect($this->reports()->ratios())->groupBy('group')->flatten(1));

        $trend = $this->reports()->monthlyTrend(12);
        $this->assertCount(12, $trend);
        $this->assertSame(now()->format('M Y'), end($trend)['month']);
        $this->assertEqualsWithDelta(
            $this->books()->incomeStatement(now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString())['total_income'],
            end($trend)['income'], 0.005);

        $this->as('manager')->get('/accounting/highlights')->assertOk()->assertSee('Financial Highlights')->assertSee('Cost-to-income ratio')->assertSee('Portfolio at risk');
    }

    // ---------- Loan aging ----------

    private function releasedLoan(Member $member, int $daysAgo): Loan
    {
        $this->as('manager');
        $service = app(LoanService::class);
        $loan = $service->apply($member, LoanProduct::where('code', 'EMG')->first(), 6000, 6, 'aging');
        $service->approve($loan);
        $service->release($loan);
        $loan->update(['released_at' => now()->subDays($daysAgo)->toDateString(), 'maturity_date' => now()->subDays($daysAgo)->addMonths(6)->toDateString()]);

        return $loan->fresh();
    }

    public function test_loan_aging_buckets_par_and_allowance(): void
    {
        Loan::query()->delete();   // start from a clean loan book
        $members = Member::where('status', 'active')->limit(3)->get();
        $this->releasedLoan($members[0], 0);      // just released: current
        $late = $this->releasedLoan($members[1], 45);    // first installment fell due about 15 days ago
        $later = $this->releasedLoan($members[2], 100);  // oldest installment about 70 days ago

        $aging = app(PortfolioReportService::class)->aging();
        $by = collect($aging['buckets'])->keyBy('key');

        $this->assertSame(1, $by['current']['count']);
        $this->assertSame(1, $by['1_30']['count']);
        $this->assertSame(1, $by['61_90']['count']);
        $this->assertSame(3, $aging['total_count']);

        $book = fn (Loan $l) => round($l->principalBalance() + $l->feesBalance(), 2);
        $this->assertEquals($book($late), $by['1_30']['balance']);
        $this->assertEquals(round($book($late) * 0.05, 2), $by['1_30']['allowance']);          // 5% for 1 to 30 days
        $this->assertEquals(round($book($later) * 0.25, 2), $by['61_90']['allowance']);        // 25% for 61 to 90 days
        $this->assertEquals(array_sum(array_column($aging['buckets'], 'allowance')), $aging['total_allowance']);

        $total = $aging['total_balance'];
        $this->assertEquals(round(($book($late) + $book($later)) / $total * 100, 1), $aging['par'][0]);   // anything past due
        $this->assertEquals(round($book($later) / $total * 100, 1), $aging['par'][30]);                  // only the 70-day loan is over 30 days
        $this->assertEquals(0.0, $aging['par'][90]);
        $this->assertGreaterThan(0, $aging['total_overdue']);

        // most overdue first
        $this->assertSame($later->loan_no, $aging['loans'][0]['loan']->loan_no);
        $this->assertGreaterThan($aging['loans'][1]['days'], $aging['loans'][0]['days']);

        $this->as('accountant')->get('/accounting/loan-aging')->assertOk()->assertSee('Suggested allowance')->assertSee($later->loan_no)->assertSee('PAR over 30 days');
        $this->as('accountant')->get('/accounting/loan-aging?export=csv')->assertOk();
    }

    // ---------- Subsidiary schedules ----------

    public function test_schedules_tie_to_the_ledger_and_catch_a_difference(): void
    {
        $s = app(PortfolioReportService::class)->schedules();
        foreach (['loans', 'savings', 'shares'] as $key) {
            $this->assertTrue($s[$key]['agrees'], $key);
            $this->assertEqualsWithDelta($s[$key]['control'], $s[$key]['total'], 0.005);
            $this->assertNotEmpty($s[$key]['rows']);
        }

        // someone moves the savings control account by hand: the schedule shows the gap
        $this->entry(now()->toDateString(), [['1010', 99, 0], ['2010', 0, 99]], 'Adjustment without a member');
        $s = app(PortfolioReportService::class)->schedules();
        $this->assertFalse($s['savings']['agrees']);
        $this->assertEquals(99, $s['savings']['difference']);
        $this->assertTrue($s['loans']['agrees']);

        $this->as('accountant')->get('/accounting/schedules')->assertOk()->assertSee('Loans receivable by borrower')->assertSee('Member savings deposits')->assertSee('Share capital by member')->assertSee('Difference');
        $this->as('accountant')->get('/accounting/schedules?export=csv')->assertOk();
    }

    // ---------- Journal and cash book ----------

    public function test_general_journal_and_cash_book(): void
    {
        $from = now()->startOfYear()->toDateString();
        $to = now()->toDateString();
        $this->entry($to, [['5030', 275, 0], ['1011', 0, 275]], 'Office rent paid by bank');

        $j = $this->reports()->generalJournal($from, $to);
        $this->assertEqualsWithDelta($j['debits'], $j['credits'], 0.005);
        $this->assertEqualsWithDelta(JournalLine::sum('debit'), $j['debits'], 0.005);
        $this->assertCount(1, $this->reports()->generalJournal($from, $to, 'manual')['entries']);
        $this->assertCount(0, $this->reports()->generalJournal($from, $to, 'closing')['entries']);

        $book = $this->reports()->cashBook($from, $to);
        $this->assertEqualsWithDelta($book['opening'] + $book['receipts'] - $book['payments'], $book['closing'], 0.005);
        $cash = collect($this->books()->trialBalance($to)['rows'])->filter(fn ($r) => in_array($r['account']->code, ['1010', '1011']))->sum(fn ($r) => $r['tb_debit'] - $r['tb_credit']);
        $this->assertEqualsWithDelta($cash, $book['closing'], 0.005);

        // a period that starts later opens with everything before it
        $later = $this->reports()->cashBook(now()->addDay()->toDateString(), now()->addDays(3)->toDateString());
        $this->assertEqualsWithDelta($book['closing'], $later['opening'], 0.005);
        $this->assertCount(0, $later['rows']);

        $this->as('auditor')->get("/accounting/general-journal?from=$from&to=$to")->assertOk()->assertSee('Office rent paid by bank')->assertSee('Totals');
        $this->as('auditor')->get("/accounting/general-journal?from=$from&to=$to&source=manual")->assertOk()->assertSee('Office rent paid by bank');
        $this->as('auditor')->get("/accounting/cash-book?from=$from&to=$to")->assertOk()->assertSee('Opening balance')->assertSee('Receipts');
        $this->as('auditor')->get("/accounting/general-journal?from=$from&to=$to&export=csv")->assertOk();
        $this->as('auditor')->get("/accounting/cash-book?from=$from&to=$to&export=csv")->assertOk();
    }

    // ---------- Year-end closing and the lock ----------

    private function lastYearActivity(int $yearsAgo = 1, int $income = 1000, int $expense = 300): int
    {
        $year = now()->year - $yearsAgo;
        $this->entry("$year-03-10", [['1010', $income, 0], ['4020', 0, $income]], "Fees $year");
        $this->entry("$year-06-15", [['5030', $expense, 0], ['1010', 0, $expense]], "Rent $year");

        return $year;
    }

    public function test_closing_a_year_moves_the_surplus_and_locks_the_books(): void
    {
        $year = $this->lastYearActivity();
        $closing = app(PeriodClosingService::class);
        $this->actingAs(User::where('role', 'accountant')->first());

        $before = $this->books()->balanceSheet(now()->toDateString());
        $isBefore = $this->books()->incomeStatement("$year-01-01", "$year-12-31");
        $this->assertEquals(700, $isBefore['net']);

        $preview = $closing->preview($year);
        $this->assertEquals(700, $preview['net']);
        $this->assertCount(2, $preview['lines']);                      // fees and rent are cleared

        $entry = $closing->close($year);
        $this->assertSame('closing', $entry->source);
        $this->assertSame("$year-12-31", $entry->entry_date->toDateString());
        $this->assertEqualsWithDelta($entry->lines->sum('debit'), $entry->lines->sum('credit'), 0.005);
        $this->assertSame("$year-12-31", $this->books()->lockedUntil()->toDateString());

        // the income statement for that year is unchanged: closing entries are left out of it
        $isAfter = $this->books()->incomeStatement("$year-01-01", "$year-12-31");
        $this->assertEquals($isBefore['total_income'], $isAfter['total_income']);
        $this->assertEquals($isBefore['net'], $isAfter['net']);

        // the surplus is now in Retained Surplus, total equity and the balance are unchanged
        $after = $this->books()->balanceSheet(now()->toDateString());
        $retained = fn ($bs) => collect($bs['equity'])->first(fn ($l) => $l['account']->code === '3020')['amount'] ?? 0;
        $this->assertEquals(700, $retained($after) - $retained($before));
        $this->assertEqualsWithDelta($before['prior_surplus'] - 700, $after['prior_surplus'], 0.005);
        $this->assertEqualsWithDelta($before['total_equity'], $after['total_equity'], 0.005);
        $this->assertTrue($after['balanced']);
        $this->assertTrue($this->books()->trialBalance(now()->toDateString())['balanced']);

        // the equity statement shows it moved
        $eq = $this->reports()->equityChanges("$year-01-01", "$year-12-31");
        $this->assertEquals(-700, $eq['surplus']['closed']);
        $this->assertTrue($eq['agrees']);

        // closed twice: not possible
        $this->expectException(ValidationException::class);
        $closing->close($year);
    }

    public function test_nothing_can_be_posted_into_a_closed_year(): void
    {
        $year = $this->lastYearActivity();
        app(PeriodClosingService::class)->close($year);   // closes as no user: fine for the service
        $count = JournalEntry::count();

        // a manual entry dated in the closed year
        $this->as('accountant')->post('/accounting/journal', [
            'entry_date' => "$year-11-02", 'description' => 'Late entry',
            'lines' => [['account_id' => $this->acct('1010')->id, 'debit' => 5], ['account_id' => $this->acct('4020')->id, 'credit' => 5]],
        ])->assertSessionHasErrors('entry_date');
        $this->assertSame($count, JournalEntry::count());

        // a receipt dated in the closed year
        $member = Member::where('status', 'active')->first();
        $this->as('cashier')->post('/collections', [
            'action' => 'post', 'member_id' => $member->id, 'collection_type' => 'savings_deposit', 'collection_date' => "$year-12-30",
            'payment_method' => 'cash', 'total_amount' => 50, 'cash_tendered' => 50,
            'lines' => [['allocation_type' => 'savings_deposit', 'amount' => 50]],
        ])->assertSessionHasErrors('lines');
        $this->assertSame($count, JournalEntry::count());

        // today is still open
        $this->entry(now()->toDateString(), [['1010', 5, 0], ['4020', 0, 5]]);
        $this->as('cashier')->post('/savings', ['kind' => 'savings_deposit', 'member_id' => $member->id, 'amount' => 10])->assertSessionHasNoErrors();
    }

    public function test_years_must_be_closed_in_order_and_can_be_reopened(): void
    {
        $older = $this->lastYearActivity(2, 900, 400);      // 500 surplus
        $newer = $this->lastYearActivity(1, 1000, 300);     // 700 surplus
        $closing = app(PeriodClosingService::class);
        $this->actingAs(User::where('role', 'accountant')->first());

        $years = collect($closing->years())->keyBy('year');
        $this->assertTrue($years[$older]['can_close']);
        $this->assertFalse($years[$newer]['can_close']);                   // the earlier year comes first
        $this->assertFalse($years[now()->year]['can_close']);              // this year has not ended

        try {
            $closing->close($newer);
            $this->fail('Closing a later year first should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('year', $e->errors());
        }

        $closing->close($older);
        $closing->close($newer);
        $this->assertSame("$newer-12-31", $this->books()->lockedUntil()->toDateString());

        // only the latest year can be reopened
        try {
            $closing->reopen($older);
            $this->fail('Reopening an earlier year first should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('year', $e->errors());
        }

        $retainedBefore = $this->books()->balanceSheet(now()->toDateString())['total_equity'];
        $closing->reopen($newer);
        $this->assertSame("$older-12-31", $this->books()->lockedUntil()->toDateString());   // locked up to the year that is still closed
        $this->assertNull($closing->closingEntry($newer));
        $this->assertTrue($this->books()->balanceSheet(now()->toDateString())['balanced']);
        $this->assertEqualsWithDelta($retainedBefore, $this->books()->balanceSheet(now()->toDateString())['total_equity'], 0.005);
        $this->assertEquals(700, $this->books()->incomeStatement("$newer-01-01", "$newer-12-31")['net']);

        // it can be closed again, with a fresh entry number
        $again = $closing->close($newer);
        $this->assertNotSame("CL-$newer", $again->entry_no);
        $this->assertSame("$newer-12-31", $this->books()->lockedUntil()->toDateString());

        $closing->reopen($newer);
        $closing->reopen($older);
        $this->assertNull($this->books()->lockedUntil());                  // nothing is closed now
    }

    public function test_closing_pages_and_permissions(): void
    {
        $year = $this->lastYearActivity();

        $this->as('manager')->get('/accounting/closing')->assertOk()->assertSee('Fiscal Years')->assertSee((string) $year);
        $this->as('accountant')->get("/accounting/closing?preview=$year")->assertOk()->assertSee("Closing $year")->assertSee('Retained Surplus');

        // only roles with the close permission can close or reopen
        $this->as('manager')->post("/accounting/closing/$year/close")->assertForbidden();
        $this->as('auditor')->post("/accounting/closing/$year/close")->assertForbidden();
        $this->as('cashier')->get('/accounting/closing')->assertForbidden();

        $this->as('accountant')->post("/accounting/closing/$year/close")->assertSessionHasNoErrors()->assertRedirect('/accounting/closing');
        $this->assertNotNull(app(PeriodClosingService::class)->closingEntry($year));
        $this->as('accountant')->get('/accounting')->assertOk()->assertSee('The books are closed up to');
        $this->as('manager')->post("/accounting/closing/$year/reopen")->assertForbidden();
        $this->as('super_admin')->post("/accounting/closing/$year/reopen")->assertSessionHasNoErrors();
        $this->assertNull(Setting::get('books_locked_until') ?: null);

        // the current year cannot be closed
        $this->as('accountant')->post('/accounting/closing/'.now()->year.'/close')->assertSessionHasErrors('year');
    }

    // ---------- Pages ----------

    public function test_every_report_page_renders_and_is_limited_to_the_right_roles(): void
    {
        $pages = ['/accounting/reports', '/accounting/cash-flow', '/accounting/changes-in-equity', '/accounting/highlights', '/accounting/loan-aging',
            '/accounting/schedules', '/accounting/general-journal', '/accounting/cash-book', '/accounting/closing', '/accounting/income-statement', '/accounting/balance-sheet'];

        foreach ($pages as $url) {
            $this->as('auditor')->get($url)->assertOk();
            $this->as('cashier')->get($url)->assertForbidden();
            $this->as('staff')->get($url)->assertForbidden();
        }

        $this->as('accountant')->get('/accounting/reports')->assertOk()->assertSee('Cash Flow Statement')->assertSee('Loan Portfolio Aging')->assertSee('Subsidiary Schedules')->assertSee('Year-end Closing');
        $this->as('accountant')->get('/accounting')->assertOk()->assertSee('All reports');
        $this->as('accountant')->get('/accounting/income-statement?compare=nonsense')->assertSessionHasErrors('compare');
        $this->as('accountant')->get('/accounting/cash-flow?from=2026-05-01&to=2026-04-01')->assertSessionHasErrors('to');
    }
}
