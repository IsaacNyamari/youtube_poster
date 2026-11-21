<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Scopes a model to the current tenant (user) and stamps new rows with the owner.
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenantId = Tenant::id();

            if ($tenantId !== null) {
                $builder->where($builder->getModel()->getTable().'.user_id', $tenantId);
            }
        });

        static::creating(function (Model $model): void {
            if ($model->getAttribute('user_id') === null) {
                $model->setAttribute('user_id', Tenant::id() ?? throw new RuntimeException('Cannot create '.class_basename($model).' without a tenant (user) context.'));
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
