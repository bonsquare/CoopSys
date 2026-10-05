<?php

namespace App\Support;

use App\Models\Organization;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * The organization the current request is working for.
 * Set when a user signs in (see AppServiceProvider and the ResolveTenant middleware).
 * One instance per request, so nothing leaks between requests.
 */
class Tenant
{
    private ?int $id = null;

    private ?Organization $organization = null;

    public static function id(): ?int
    {
        return app(self::class)->id;
    }

    public static function current(): ?Organization
    {
        $tenant = app(self::class);

        if ($tenant->id && (! $tenant->organization || $tenant->organization->id !== $tenant->id)) {
            $tenant->organization = Organization::find($tenant->id);
        }

        return $tenant->id ? $tenant->organization : null;
    }

    public static function set(?int $id): void
    {
        $tenant = app(self::class);
        if ($tenant->id !== $id) {
            $tenant->organization = null;
        }
        $tenant->id = $id;
    }

    public static function forget(): void
    {
        self::set(null);
    }

    /** Validation: the id must exist AND belong to this organization. */
    public static function exists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)->where(fn ($q) => $q->where('organization_id', self::id()));
    }

    /** Validation: unique within this organization only. */
    public static function unique(string $table, string $column, ?int $ignoreId = null): Unique
    {
        return Rule::unique($table, $column)->where(fn ($q) => $q->where('organization_id', self::id()))->ignore($ignoreId);
    }
}
