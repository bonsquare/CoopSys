<?php

namespace App\Console\Commands;

use App\Models\Organization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('organization:status {code : The Organization ID, e.g. CS-7K4Q2M} {status : active or suspended}')]
#[Description('Suspend or reactivate an organization (suspended organizations cannot sign in)')]
class OrganizationStatus extends Command
{
    public function handle(): int
    {
        $status = $this->argument('status');
        if (! in_array($status, ['active', 'suspended'], true)) {
            $this->error('Status must be "active" or "suspended".');

            return self::FAILURE;
        }

        $organization = Organization::findByCode($this->argument('code'));
        if (! $organization) {
            $this->error('No organization has that ID.');

            return self::FAILURE;
        }

        $organization->update(['status' => $status]);
        $this->info("{$organization->name} ({$organization->code}) is now {$status}.");

        return self::SUCCESS;
    }
}
