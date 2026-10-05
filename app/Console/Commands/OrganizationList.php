<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('organization:list')]
#[Description('List every organization with its plan, status and size')]
class OrganizationList extends Command
{
    public function handle(): int
    {
        $rows = Organization::orderBy('id')->get()->map(fn (Organization $o) => [
            $o->code,
            $o->name,
            $o->plan,
            $o->status,
            $o->trial_ends_at?->toDateString() ?? '-',
            User::where('organization_id', $o->id)->count(),
            Member::withoutGlobalScopes()->where('organization_id', $o->id)->count(),
        ]);

        $this->table(['Organization ID', 'Name', 'Plan', 'Status', 'Trial ends', 'Users', 'Members'], $rows);

        return self::SUCCESS;
    }
}
