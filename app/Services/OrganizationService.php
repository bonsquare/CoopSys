<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use App\Support\Tenant;
use Database\Seeders\LoanProductSeeder;
use Illuminate\Support\Facades\DB;

/** Sets up a brand-new organization: its record, chart of accounts, loan products, settings and first administrator. */
class OrganizationService
{
    /**
     * @param  array{name: string, email?: ?string, phone?: ?string, plan?: string}  $organization
     * @param  array{name: string, email: string, password: string}  $admin
     * @return array{0: Organization, 1: User} The tenant is left set to the new organization.
     */
    public function provision(array $organization, array $admin): array
    {
        return DB::transaction(function () use ($organization, $admin) {
            $plan = $organization['plan'] ?? 'trial';

            $org = Organization::create([
                'code' => Organization::generateCode(),
                'name' => $organization['name'],
                'email' => $organization['email'] ?? null,
                'phone' => $organization['phone'] ?? null,
                'status' => 'active',
                'plan' => $plan,
                'trial_ends_at' => $plan === 'trial' ? now()->addDays(config('coop.trial_days'))->toDateString() : null,
            ]);

            Tenant::set($org->id);

            $this->chartOfAccounts();
            app(LoanProductSeeder::class)->run();
            Setting::put('coop_name', $org->name);
            Setting::put('branch_name', 'Main Branch');

            $user = new User(['name' => $admin['name'], 'email' => $admin['email'], 'role' => 'super_admin', 'is_active' => true, 'password' => $admin['password']]);
            $user->organization_id = $org->id;
            $user->save();

            return [$org, $user];
        });
    }

    private function chartOfAccounts(): void
    {
        foreach (config('accounting.default_chart') as [$code, $name, $type, $system]) {
            Account::create(['code' => $code, 'name' => $name, 'type' => $type, 'is_system' => $system, 'is_active' => true, 'report_group' => config("accounting.groups.$code")]);
        }
    }
}
