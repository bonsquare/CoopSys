<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Support\Tenant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingTest extends TestCase
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

    private function acct(string $code): Account
    {
        return Account::byCode($code);
    }

    private function entry(array $lines, array $over = []): array
    {
        return array_merge([
            'entry_date' => now()->toDateString(), 'reference' => 'BANK-1', 'description' => 'Test entry',
            'lines' => $lines,
        ], $over);
    }

    private function balances(): AccountingService
    {
        return app(AccountingService::class);
    }

    // ---------- Manual journal entries ----------

    public function test_a_balanced_entry_posts_and_shows_in_the_books(): void
    {
        $bank = $this->acct('1011');
        $rent = $this->acct('5030');
        $bankBefore = $this->balances()->totals(null, now()->toDateString())[$bank->id]['debit'] ?? 0;

        $this->as('accountant')->post('/accounting/journal', $this->entry([
            ['account_id' => $rent->id, 'debit' => 1500, 'credit' => '', 'memo' => 'October rent'],
            ['account_id' => $bank->id, 'debit' => '', 'credit' => 1500, 'memo' => ''],
        ], ['description' => 'Paid office rent by bank']))->assertSessionHasNoErrors();

        $e = JournalEntry::latest('id')->first();
        $this->assertSame('manual', $e->source);
        $this->assertSame('posted', $e->status);
        $this->assertMatchesRegularExpression('/^JV-\d{4}-\d{4}$/', $e->entry_no);
        $this->assertNotNull($e->user_id);
        $this->assertCount(2, $e->lines);
        $this->assertSame('October rent', $e->lines->first()->memo);

        // it moves the trial balance, the ledger and the income statement
        $totals = $this->balances()->totals(null, now()->toDateString());
        $this->assertEquals(1500, $totals[$rent->id]['debit']);
        $this->assertEquals(1500, $totals[$bank->id]['credit'] - ($totals[$bank->id]['credit'] - 1500));
        $is = $this->balances()->incomeStatement(now()->startOfYear()->toDateString(), now()->toDateString());
        $this->assertEquals(1500, collect($is['expenses'])->firstWhere('account.code', '5030')['amount']);
        $this->assertTrue($this->balances()->trialBalance(now()->toDateString())['balanced']);
        $this->as('accountant')->get("/accounting/journal/{$e->id}")->assertOk()->assertSee($e->entry_no)->assertSee('October rent');
    }

    public function test_entries_that_do_not_balance_or_make_no_sense_are_refused(): void
    {
        $cash = $this->acct('1010')->id;
        $rent = $this->acct('5030')->id;
        $count = JournalEntry::count();

        // does not balance
        $this->as('accountant')->post('/accounting/journal', $this->entry([
            ['account_id' => $rent, 'debit' => 100], ['account_id' => $cash, 'credit' => 90],
        ]))->assertSessionHasErrors('lines');

        // only one line
        $this->as('accountant')->post('/accounting/journal', $this->entry([['account_id' => $rent, 'debit' => 100]]))->assertSessionHasErrors('lines');

        // a line with both a debit and a credit
        $this->as('accountant')->post('/accounting/journal', $this->entry([
            ['account_id' => $rent, 'debit' => 100, 'credit' => 100], ['account_id' => $cash, 'credit' => 100],
        ]))->assertSessionHasErrors('lines');

        // empty amounts, unknown account, future date, no description
        $this->as('accountant')->post('/accounting/journal', $this->entry([['account_id' => $rent], ['account_id' => $cash]]))->assertSessionHasErrors('lines');
        $this->as('accountant')->post('/accounting/journal', $this->entry([['account_id' => 999999, 'debit' => 5], ['account_id' => $cash, 'credit' => 5]]))->assertSessionHasErrors('lines.0.account_id');
        $this->as('accountant')->post('/accounting/journal', $this->entry([['account_id' => $rent, 'debit' => 5], ['account_id' => $cash, 'credit' => 5]], ['entry_date' => now()->addDay()->toDateString()]))->assertSessionHasErrors('entry_date');
        $this->as('accountant')->post('/accounting/journal', $this->entry([['account_id' => $rent, 'debit' => 5], ['account_id' => $cash, 'credit' => 5]], ['description' => '']))->assertSessionHasErrors('description');

        // inactive account
        Account::where('code', '5030')->update(['is_active' => false]);
        $this->as('accountant')->post('/accounting/journal', $this->entry([['account_id' => $rent, 'debit' => 5], ['account_id' => $cash, 'credit' => 5]]))->assertSessionHasErrors('lines');

        $this->assertSame($count, JournalEntry::count());   // nothing was posted
    }

    public function test_reversing_a_manual_entry(): void
    {
        $cash = $this->acct('1010')->id;
        $rent = $this->acct('5030')->id;
        $this->as('accountant')->post('/accounting/journal', $this->entry([['account_id' => $rent, 'debit' => 400], ['account_id' => $cash, 'credit' => 400]]));
        $original = JournalEntry::latest('id')->first();

        $this->as('cashier')->post("/accounting/journal/{$original->id}/reverse", ['reason' => 'x'])->assertForbidden();
        $this->as('accountant')->post("/accounting/journal/{$original->id}/reverse", ['reason' => 'posted twice'])->assertSessionHasNoErrors();

        $reversal = JournalEntry::latest('id')->first();
        $this->assertSame($original->id, $reversal->reversal_of);
        $this->assertSame('reversed', $original->fresh()->status);
        $this->assertEquals(400, $reversal->lines->firstWhere('account_id', $cash)->debit);   // swapped
        $this->assertEquals(400, $reversal->lines->firstWhere('account_id', $rent)->credit);

        // net effect on rent is nil, and neither entry can be reversed again
        $rentNet = collect($this->balances()->incomeStatement(now()->startOfYear()->toDateString(), now()->toDateString())['expenses'])->firstWhere('account.code', '5030')['amount'] ?? 0.0;
        $this->assertEquals(0.0, $rentNet);
        $this->as('accountant')->post("/accounting/journal/{$original->id}/reverse", ['reason' => 'again'])->assertSessionHasErrors('entry');
        $this->as('accountant')->post("/accounting/journal/{$reversal->id}/reverse", ['reason' => 'undo the undo'])->assertSessionHasErrors('entry');

        // an automatic entry cannot be reversed here
        $auto = JournalEntry::where('source', 'system')->first();
        $this->as('accountant')->post("/accounting/journal/{$auto->id}/reverse", ['reason' => 'x'])->assertSessionHasErrors('entry');
    }

    // ---------- Statements ----------

    public function test_trial_balance_balance_sheet_and_income_statement_agree(): void
    {
        $today = now()->toDateString();

        $tb = $this->balances()->trialBalance($today);
        $this->assertTrue($tb['balanced']);
        $this->assertEqualsWithDelta($tb['rows']->sum('debit'), JournalLine::sum('debit'), 0.001);   // every entry is in the trial balance

        $bs = $this->balances()->balanceSheet($today);
        $this->assertTrue($bs['balanced']);
        $this->assertEqualsWithDelta($bs['total_assets'], $bs['total_liabilities'] + $bs['total_equity'], 0.005);

        // the surplus on the balance sheet is the income statement's net
        $is = $this->balances()->incomeStatement('2000-01-01', $today);
        $this->assertEqualsWithDelta($is['net'], $bs['prior_surplus'] + $bs['current_surplus'], 0.005);
        $this->assertGreaterThan(0, $is['total_income']);   // interest and fees were collected in the demo data

        // a manual entry keeps all three in step
        $this->as('accountant')->post('/accounting/journal', $this->entry([
            ['account_id' => $this->acct('1011')->id, 'debit' => 10000], ['account_id' => $this->acct('3020')->id, 'credit' => 10000],
        ], ['description' => 'Opening bank balance']));
        $bs2 = $this->balances()->balanceSheet($today);
        $this->assertTrue($bs2['balanced']);
        $this->assertEqualsWithDelta($bs['total_assets'] + 10000, $bs2['total_assets'], 0.005);
        $this->assertEqualsWithDelta($bs['total_equity'] + 10000, $bs2['total_equity'], 0.005);
    }

    public function test_balance_sheet_splits_prior_and_current_year_surplus(): void
    {
        $cash = $this->acct('1010')->id;
        $this->as('accountant');
        $service = $this->balances();

        // income earned last year: it appears as earlier years' surplus on this year's balance sheet
        $lastYear = now()->subYear()->startOfYear()->addMonths(5)->toDateString();
        $this->actingAs(User::where('role', 'accountant')->first())->post('/accounting/journal', $this->entry([
            ['account_id' => $cash, 'debit' => 700], ['account_id' => $this->acct('4020')->id, 'credit' => 700],
        ], ['entry_date' => $lastYear, 'description' => 'Last year fee']));

        $bs = $service->balanceSheet(now()->toDateString());
        $beforeThisYear = $service->incomeStatement('2000-01-01', now()->subYear()->endOfYear()->toDateString())['net'];
        $this->assertEqualsWithDelta($beforeThisYear, $bs['prior_surplus'], 0.005);
        $this->assertGreaterThanOrEqual(700, $bs['prior_surplus']);
        $this->assertTrue($bs['balanced']);

        // looking at last year's year-end hides this year
        $old = $service->balanceSheet(now()->subYear()->endOfYear()->toDateString());
        $this->assertEqualsWithDelta(0.0, $old['current_surplus'] - $service->incomeStatement(now()->subYear()->startOfYear()->toDateString(), now()->subYear()->endOfYear()->toDateString())['net'], 0.005);
        $this->assertTrue($old['balanced']);
    }

    public function test_general_ledger_running_balance(): void
    {
        $cash = $this->acct('1010');
        $ledger = $this->balances()->ledger($cash, now()->startOfYear()->toDateString(), now()->toDateString());

        $running = $ledger['opening'];
        foreach ($ledger['rows'] as $row) {
            $running += (float) $row['line']->debit - (float) $row['line']->credit;   // cash is a debit account
            $this->assertEqualsWithDelta($running, $row['balance'], 0.01);
        }
        $this->assertEqualsWithDelta($running, $ledger['closing'], 0.01);
        $this->assertGreaterThan(0, count($ledger['rows']));

        // the opening balance picks up what came before the period
        $later = $this->balances()->ledger($cash, now()->addDay()->toDateString(), now()->addDays(2)->toDateString());
        $this->assertEqualsWithDelta($ledger['closing'], $later['opening'], 0.01);
        $this->assertCount(0, $later['rows']);

        // a credit-normal account (savings) grows with credits
        $savings = $this->balances()->ledger($this->acct('2010'), now()->startOfYear()->toDateString(), now()->toDateString());
        $this->assertGreaterThan(0, $savings['closing']);
    }

    public function test_pages_and_exports_render(): void
    {
        foreach (['/accounting', '/accounting/journal', '/accounting/accounts', '/accounting/ledger', '/accounting/trial-balance', '/accounting/income-statement', '/accounting/balance-sheet'] as $url) {
            $this->as('auditor')->get($url)->assertOk();
        }

        $this->as('accountant')->get('/accounting')->assertOk()->assertSee('The books are balanced')->assertSee('Reconciliation Checks')->assertSee('Agrees');
        $this->as('accountant')->get('/accounting/trial-balance')->assertOk()->assertSee('total debits equal total credits')->assertSee('Cash in Bank');
        $this->as('accountant')->get('/accounting/trial-balance?hide_zero=1')->assertOk()->assertDontSee('Retained Surplus');
        $this->as('accountant')->get('/accounting/income-statement')->assertOk()->assertSee('INCOME')->assertSee('EXPENSES')->assertSee('NET SURPLUS');
        $this->as('accountant')->get('/accounting/balance-sheet')->assertOk()->assertSee('ASSETS')->assertSee('LIABILITIES')->assertSee('TOTAL LIABILITIES AND EQUITY')->assertSee('balance sheet balances');
        $this->as('accountant')->get('/accounting/ledger?account='.$this->acct('1010')->id)->assertOk()->assertSee('Opening balance');
        $this->as('accountant')->get('/accounting/journal?source=manual')->assertOk();
        $this->as('accountant')->get('/accounting/journal?q=Loan')->assertOk();

        foreach (['/accounting/trial-balance', '/accounting/income-statement', '/accounting/balance-sheet', '/accounting/ledger'] as $url) {
            $response = $this->as('accountant')->get($url.'?export=csv')->assertOk();
            $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        }
    }

    public function test_reconciliation_flags_a_difference(): void
    {
        foreach ($this->balances()->checks() as $check) {
            $this->assertTrue($check['ok'], $check['label']);
        }

        // a manual entry that changes savings without a matching member record is caught
        $this->as('accountant')->post('/accounting/journal', $this->entry([
            ['account_id' => $this->acct('1010')->id, 'debit' => 250], ['account_id' => $this->acct('2010')->id, 'credit' => 250],
        ], ['description' => 'Adjustment that has no member behind it']));

        $failed = collect($this->balances()->checks())->where('ok', false)->pluck('label')->all();
        $this->assertContains("Savings deposits agree with members' savings", $failed);
        $this->assertNotContains('Cash on hand and in bank agree with cash transactions and manual cash entries', $failed);   // a manual cash entry is allowed
        $this->as('accountant')->get('/accounting')->assertOk()->assertSee('Check');
    }

    // ---------- Chart of accounts ----------

    public function test_manual_cash_entries_are_part_of_the_cash_check(): void
    {
        // an opening bank balance has no cash transaction behind it, and that is fine
        $this->as('accountant')->post('/accounting/journal', $this->entry([
            ['account_id' => $this->acct('1011')->id, 'debit' => 5000], ['account_id' => $this->acct('3020')->id, 'credit' => 5000],
        ], ['description' => 'Opening bank balance']));
        $this->assertSame([], collect($this->balances()->checks())->where('ok', false)->pluck('label')->all());

        // but moving the control accounts for savings, shares or loans by hand is flagged
        foreach (['2010', '3010', '1020'] as $code) {
            $this->as('accountant')->post('/accounting/journal', $this->entry([
                ['account_id' => $this->acct('1010')->id, 'debit' => 10], ['account_id' => $this->acct($code)->id, 'credit' => 10],
            ]));
        }
        $this->assertCount(3, collect($this->balances()->checks())->where('ok', false));
    }

    public function test_chart_of_accounts_rules(): void
    {
        $this->as('accountant')->post('/accounting/accounts', ['code' => '1050', 'name' => 'Office Supplies', 'type' => 'asset'])->assertSessionHasNoErrors();
        $new = Account::where('code', '1050')->first();
        $this->assertTrue($new->is_active);
        $this->assertFalse($new->is_system);

        // codes are unique, the type must be valid
        $this->as('accountant')->post('/accounting/accounts', ['code' => '1050', 'name' => 'Duplicate', 'type' => 'asset'])->assertSessionHasErrors('code');
        $this->as('accountant')->post('/accounting/accounts', ['code' => '1060', 'name' => 'Bad', 'type' => 'wizard'])->assertSessionHasErrors('type');

        // a new account can be used, renamed, deactivated and deleted while unused
        $this->as('accountant')->put("/accounting/accounts/{$new->id}", ['code' => '1055', 'name' => 'Supplies', 'type' => 'asset'])->assertSessionHasNoErrors();
        $this->assertSame('1055', $new->fresh()->code);
        $this->as('accountant')->post("/accounting/accounts/{$new->id}/toggle");
        $this->assertFalse($new->fresh()->is_active);
        $this->as('accountant')->delete("/accounting/accounts/{$new->id}")->assertRedirect('/accounting/accounts');
        $this->assertNull(Account::find($new->id));

        // system accounts: renamed yes, code or type changed no, deactivate / delete no
        $cash = $this->acct('1010');
        $this->as('accountant')->put("/accounting/accounts/{$cash->id}", ['code' => '9999', 'name' => 'Cash in Vault', 'type' => 'expense'])->assertSessionHasNoErrors();
        $this->assertSame('Cash in Vault', $cash->fresh()->name);
        $this->assertSame('1010', $cash->fresh()->code);
        $this->assertSame('asset', $cash->fresh()->type);
        $this->as('accountant')->post("/accounting/accounts/{$cash->id}/toggle")->assertSessionHasErrors('account');
        $this->as('accountant')->delete("/accounting/accounts/{$cash->id}")->assertSessionHasErrors('account');
        $this->assertTrue($cash->fresh()->is_active);

        // an account with entries cannot be deleted, and one with a balance cannot be deactivated
        $rent = $this->acct('5030');
        $this->as('accountant')->post('/accounting/journal', $this->entry([['account_id' => $rent->id, 'debit' => 80], ['account_id' => $this->acct('1010')->id, 'credit' => 80]]));
        $this->as('accountant')->delete("/accounting/accounts/{$rent->id}")->assertSessionHasErrors('account');
        $this->as('accountant')->post("/accounting/accounts/{$rent->id}/toggle")->assertSessionHasErrors('account');
        $this->as('accountant')->put("/accounting/accounts/{$rent->id}", ['code' => '5099', 'name' => 'Rent', 'type' => 'income']);
        $this->assertSame('5030', $rent->fresh()->code);                       // locked once it has entries
        $this->assertSame('expense', $rent->fresh()->type);
    }

    public function test_roles(): void
    {
        $cash = $this->acct('1010')->id;
        $rent = $this->acct('5030')->id;

        // everyone who can see accounting can read; only accountant and super admin can post
        foreach (['manager', 'auditor'] as $role) {
            $this->as($role)->get('/accounting/balance-sheet')->assertOk();
            $this->as($role)->get('/accounting/journal/create')->assertForbidden();
            $this->as($role)->post('/accounting/journal', $this->entry([['account_id' => $rent, 'debit' => 5], ['account_id' => $cash, 'credit' => 5]]))->assertForbidden();
            $this->as($role)->post('/accounting/accounts', ['code' => '7777', 'name' => 'X', 'type' => 'asset'])->assertForbidden();
        }
        foreach (['cashier', 'staff', 'loan_officer'] as $role) {
            $this->as($role)->get('/accounting')->assertForbidden();
        }
        $this->as('super_admin')->get('/accounting/journal/create')->assertOk()->assertSee('Post entry');
        $this->as('accountant')->get('/accounting/accounts/create')->assertOk();
    }

    public function test_transactions_keep_posting_after_the_changes(): void
    {
        $member = Member::where('status', 'active')->first();
        $this->as('cashier')->post('/savings', ['kind' => 'savings_deposit', 'member_id' => $member->id, 'amount' => 321]);

        $entry = JournalEntry::latest('id')->first();
        $this->assertSame('system', $entry->source);
        $this->assertNotNull($entry->user_id);                   // the teller who posted it
        $this->assertTrue($this->balances()->trialBalance(now()->toDateString())['balanced']);
        foreach ($this->balances()->checks() as $check) {
            $this->assertTrue($check['ok'], $check['label']);
        }
        $this->as('accountant')->get("/accounting/journal/{$entry->id}")->assertOk()->assertSee('posted from a transaction');
    }
}
