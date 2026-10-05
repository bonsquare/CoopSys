<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\OrganizationService;
use App\Support\Tenant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $demo;

    private Organization $other;

    private User $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->demo = Organization::where('code', 'CS-DEMO01')->firstOrFail();

        // A second customer with the SAME email address as the demo admin
        [$this->other, $this->otherAdmin] = app(OrganizationService::class)->provision(
            ['name' => 'Other Credit Union'],
            ['name' => 'Other Admin', 'email' => 'admin@coopsys.test', 'password' => 'password'],
        );
        Tenant::set($this->other->id);
        Member::create(['member_no' => 'MEM-OTHER-0001', 'first_name' => 'Only', 'last_name' => 'Others', 'date_joined' => now(), 'status' => 'active']);
        Tenant::forget();
    }

    private function inDemo(): static
    {
        return $this->actingAs(User::where('organization_id', $this->demo->id)->where('role', 'super_admin')->firstOrFail());
    }

    // ---------- Registration ----------

    public function test_a_new_organization_registers_with_its_own_books(): void
    {
        $this->post('/register', [
            'organization_name' => 'Brand New Coop', 'name' => 'Nora Owner', 'email' => 'nora@new.test',
            'password' => 'longenough1', 'password_confirmation' => 'longenough1', 'terms' => 1,
        ])->assertRedirect('/dashboard');

        $org = Organization::where('name', 'Brand New Coop')->firstOrFail();
        $this->assertMatchesRegularExpression('/^CS-[A-Z2-9]{6}$/', $org->code);
        $this->assertSame('trial', $org->plan);
        $this->assertSame(now()->addDays(30)->toDateString(), $org->trial_ends_at->toDateString());

        $admin = User::where('email', 'nora@new.test')->firstOrFail();
        $this->assertSame($org->id, $admin->organization_id);
        $this->assertSame('super_admin', $admin->role);
        $this->assertAuthenticatedAs($admin);

        // chart of accounts, loan products and settings were created for this organization only
        $this->assertSame(17, Account::withoutGlobalScopes()->where('organization_id', $org->id)->count());
        $this->assertGreaterThan(0, LoanProduct::withoutGlobalScopes()->where('organization_id', $org->id)->count());
        $this->assertSame('Brand New Coop', Setting::withoutGlobalScopes()->where('organization_id', $org->id)->where('key', 'coop_name')->value('value'));
        $this->get('/dashboard')->assertOk()->assertSee($org->code);
    }

    public function test_registration_needs_the_terms_and_can_be_closed(): void
    {
        $data = ['organization_name' => 'X Coop', 'name' => 'X', 'email' => 'x@x.test', 'password' => 'longenough1', 'password_confirmation' => 'longenough1'];
        $this->post('/register', $data)->assertSessionHasErrors('terms');
        $this->assertSame(0, Organization::where('name', 'X Coop')->count());

        config(['coop.registration' => false]);
        $this->get('/register')->assertNotFound();
        $this->post('/register', $data + ['terms' => 1])->assertNotFound();
    }

    // ---------- Sign in ----------

    public function test_sign_in_needs_the_right_organization_id(): void
    {
        $login = fn (string $org) => $this->post('/login', ['organization' => $org, 'email' => 'admin@coopsys.test', 'password' => 'password']);

        $login('CS-NOPE99')->assertSessionHasErrors('email');
        $this->assertGuest();

        // same email, different organizations: each ID opens its own account
        $login('cs-demo01')->assertRedirect('/dashboard');            // case and spacing do not matter
        $this->assertSame($this->demo->id, auth()->user()->organization_id);
        $this->post('/logout');

        $login($this->other->code)->assertRedirect('/dashboard');
        $this->assertSame($this->other->id, auth()->user()->organization_id);
    }

    public function test_suspended_organizations_cannot_sign_in_or_keep_working(): void
    {
        $this->inDemo()->get('/dashboard')->assertOk();

        $this->demo->update(['status' => 'suspended']);
        $this->get('/dashboard')->assertRedirect('/login');           // an open session is ended
        $this->assertGuest();

        $this->post('/login', ['organization' => 'CS-DEMO01', 'email' => 'admin@coopsys.test', 'password' => 'password'])->assertSessionHasErrors('organization');
        $this->assertGuest();
    }

    // ---------- Isolation ----------

    public function test_each_organization_only_sees_its_own_records(): void
    {
        $this->inDemo();
        $this->assertSame(10, Member::count());
        $this->assertSame(0, Member::where('first_name', 'Only')->count());

        $this->get('/members')->assertOk()->assertDontSee('MEM-OTHER-0001');

        $theirs = Member::withoutGlobalScopes()->where('organization_id', $this->other->id)->firstOrFail();
        $this->get("/members/{$theirs->id}")->assertNotFound();
        $this->get("/members/{$theirs->id}/edit")->assertNotFound();

        // the other organization sees only its own member
        $this->actingAs($this->otherAdmin);
        $this->assertSame(1, Member::count());
        $this->get('/members')->assertOk()->assertSee('MEM-OTHER-0001')->assertDontSee('Eduardo');
    }

    public function test_records_cannot_be_used_across_organizations(): void
    {
        $theirs = Member::withoutGlobalScopes()->where('organization_id', $this->other->id)->firstOrFail();

        // a teller cannot post to another organization's member
        $this->inDemo()->post('/savings', [
            'kind' => 'savings_deposit', 'member_id' => $theirs->id, 'amount' => 100, 'payment_method' => 'cash',
        ])->assertSessionHasErrors('member_id');

        $theirAccount = Account::withoutGlobalScopes()->where('organization_id', $this->other->id)->where('code', '5010')->firstOrFail();
        $mine = Account::byCode('1010');
        $this->post('/accounting/journal', [
            'entry_date' => now()->toDateString(), 'description' => 'Sneaky',
            'lines' => [['account_id' => $theirAccount->id, 'debit' => 10, 'credit' => ''], ['account_id' => $mine->id, 'debit' => '', 'credit' => 10]],
        ])->assertSessionHasErrors();
    }

    public function test_accounting_totals_and_settings_are_separate(): void
    {
        $demoCash = Account::byCode('1010');
        $demoTotals = app(AccountingService::class)->totals(null, now()->toDateString());
        $this->assertGreaterThan(0, $demoTotals[$demoCash->id]['debit']);

        $this->actingAs($this->otherAdmin);
        $this->assertSame([], app(AccountingService::class)->totals(null, now()->toDateString()));   // nothing posted yet
        $this->assertTrue(app(AccountingService::class)->checks()[0]['ok']);

        Setting::put('min_balance', '999');
        AuditLog::record('test.secret', 'only-the-other-org-sees-this');
        $this->actingAs(User::where('organization_id', $this->demo->id)->where('role', 'super_admin')->first());
        $this->assertNotSame('999', Setting::get('min_balance'));
        $this->assertSame(0, AuditLog::where('description', 'only-the-other-org-sees-this')->count());
    }

    public function test_codes_may_repeat_between_organizations_but_not_inside_one(): void
    {
        // both organizations have account 1010 and member numbers like MEM-OTHER-0001 without clashing
        $this->assertSame(2, Account::withoutGlobalScopes()->where('code', '1010')->count());

        $this->inDemo()->post('/accounting/accounts', ['code' => '1010', 'name' => 'Dup', 'type' => 'asset'])->assertSessionHasErrors('code');
        $this->post('/accounting/accounts', ['code' => '1099', 'name' => 'Petty Cash', 'type' => 'asset'])->assertSessionHasNoErrors();

        $this->actingAs($this->otherAdmin)->post('/accounting/accounts', ['code' => '1099', 'name' => 'Their Petty Cash', 'type' => 'asset'])->assertSessionHasNoErrors();
    }

    public function test_user_management_stays_inside_the_organization(): void
    {
        $this->inDemo()->get('/users')->assertOk()->assertDontSee('Other Admin');

        $theirs = $this->otherAdmin;
        $this->get("/users/{$theirs->id}/edit")->assertNotFound();
        $this->post("/users/{$theirs->id}/toggle")->assertNotFound();

        // the same email is fine in another organization, but not twice in the same one
        $this->actingAs($this->otherAdmin)->post('/users', ['name' => 'Cashier', 'email' => 'cashier@coopsys.test', 'role' => 'cashier', 'password' => 'longenough1', 'password_confirmation' => 'longenough1'])
            ->assertSessionHasNoErrors();
        $this->post('/users', ['name' => 'Again', 'email' => 'cashier@coopsys.test', 'role' => 'cashier', 'password' => 'longenough1', 'password_confirmation' => 'longenough1'])
            ->assertSessionHasErrors('email');
    }

    // ---------- Plans ----------

    public function test_the_plan_limits_users_and_members(): void
    {
        config(['coop.plans.unlimited.limits.members' => 10, 'coop.plans.unlimited.limits.users' => 7]);
        $this->inDemo();

        $this->post('/members', ['first_name' => 'Over', 'last_name' => 'Limit', 'date_joined' => now()->toDateString(), 'status' => 'active', 'subscribed_shares' => 0])
            ->assertSessionHasErrors('first_name');
        $this->assertSame(10, Member::count());

        $this->post('/users', ['name' => 'Eighth', 'email' => 'eighth@coopsys.test', 'role' => 'staff', 'password' => 'longenough1', 'password_confirmation' => 'longenough1'])
            ->assertSessionHasErrors('email');
    }

    public function test_an_ended_trial_is_read_only(): void
    {
        $this->demo->update(['plan' => 'trial', 'trial_ends_at' => now()->subDay()->toDateString()]);

        $this->inDemo()->get('/members')->assertOk()->assertSee('free trial has ended');
        $this->post('/accounting/accounts', ['code' => '1098', 'name' => 'Blocked', 'type' => 'asset'])->assertSessionHasErrors('trial');
        $this->assertSame(0, Account::where('code', '1098')->count());

        $this->demo->update(['trial_ends_at' => now()->addDays(3)->toDateString()]);
        $this->get('/members')->assertSee('3 days left');
        $this->post('/accounting/accounts', ['code' => '1098', 'name' => 'Allowed', 'type' => 'asset'])->assertSessionHasNoErrors();
    }

    // ---------- Admin commands ----------

    public function test_artisan_commands_suspend_and_change_plans(): void
    {
        $this->artisan('organization:status', ['code' => 'cs-demo01', 'status' => 'suspended'])->assertSuccessful();
        $this->assertSame('suspended', $this->demo->fresh()->status);

        $this->artisan('organization:status', ['code' => 'CS-DEMO01', 'status' => 'active'])->assertSuccessful();
        $this->artisan('organization:plan', ['code' => 'CS-DEMO01', 'plan' => 'trial', '--trial-days' => 10])->assertSuccessful();
        $this->assertSame('trial', $this->demo->fresh()->plan);
        $this->assertSame(now()->addDays(10)->toDateString(), $this->demo->fresh()->trial_ends_at->toDateString());

        $this->artisan('organization:status', ['code' => 'NOPE', 'status' => 'active'])->assertFailed();
        $this->artisan('organization:list')->assertSuccessful();
    }
}
