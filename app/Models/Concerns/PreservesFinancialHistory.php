<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Protects model writes. Query-builder updates and direct SQL bypass model events
 * and must not be used to mutate financial configuration.
 */
trait PreservesFinancialHistory
{
    protected static function bootPreservesFinancialHistory(): void
    {
        static::updating(function (Model $model): void {
            throw new LogicException('Financial configuration is immutable. Create a new version instead.');
        });

        static::deleting(function (Model $model): void {
            throw new LogicException('Financial configuration history cannot be deleted.');
        });
    }
}
