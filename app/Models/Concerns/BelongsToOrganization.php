<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Keeps a model's rows inside one organization.
 * Every query is limited to the signed-in user's organization and new rows are stamped with it.
 */
trait BelongsToOrganization
{
    protected static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $query) {
            if ($id = Tenant::id()) {
                $query->where($query->getModel()->getTable().'.organization_id', $id);
            }
        });

        static::creating(function ($model) {
            if (! $model->organization_id && ($id = Tenant::id())) {
                $model->organization_id = $id;
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
