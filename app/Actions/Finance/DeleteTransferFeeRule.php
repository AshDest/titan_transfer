<?php

namespace App\Actions\Finance;

use App\Models\TransferFeeRule;
use App\Models\User;
use App\Services\OperationNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DeleteTransferFeeRule
{
    public function handle(User $actor, TransferFeeRule $rule): void
    {
        Gate::forUser($actor)->authorize('delete', $rule);

        DB::transaction(function () use ($actor, $rule): void {
            $rule = TransferFeeRule::whereKey($rule->id)->lockForUpdate()->firstOrFail();
            if ($rule->deleted_at !== null) {
                return;
            }
            $rule->deleted_at = now('UTC');
            $rule->save();
            app(OperationNotifications::class)->record($rule, $actor, 'deleted');
        }, 3);
    }
}
