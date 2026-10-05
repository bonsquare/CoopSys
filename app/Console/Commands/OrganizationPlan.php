<?php

namespace App\Console\Commands;

use App\Models\Organization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('organization:plan {code : The Organization ID} {plan : A plan from config/coop.php} {--trial-days= : Extend or restart a trial by this many days}')]
#[Description('Change an organization\'s plan, or extend its free trial')]
class OrganizationPlan extends Command
{
    public function handle(): int
    {
        $plan = $this->argument('plan');
        if (! array_key_exists($plan, config('coop.plans'))) {
            $this->error('Unknown plan. Choose one of: '.implode(', ', array_keys(config('coop.plans'))));

            return self::FAILURE;
        }

        $organization = Organization::findByCode($this->argument('code'));
        if (! $organization) {
            $this->error('No organization has that ID.');

            return self::FAILURE;
        }

        $trialEnds = $plan === 'trial'
            ? now()->addDays((int) ($this->option('trial-days') ?: config('coop.trial_days')))->toDateString()
            : null;
        $organization->update(['plan' => $plan, 'trial_ends_at' => $trialEnds]);
        $this->info("{$organization->name} ({$organization->code}) is now on the {$plan} plan.");

        return self::SUCCESS;
    }
}
