<?php

namespace App\Actions\Finance;

use App\Models\ExchangeRate;
use App\Models\Transfer;
use App\Models\User;
use App\Services\ExchangeRateLock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DeleteExchangeRate
{
    public function handle(User $actor, ExchangeRate $rate): void
    {
        Gate::forUser($actor)->authorize('delete', $rate);
        $message = 'Seul un taux désactivé et jamais utilisé dans un transfert peut être supprimé.';
        try {
            DB::transaction(function () use ($rate, $message): void {
                app(ExchangeRateLock::class)->acquire($rate->from_currency, $rate->to_currency);
                $rate = ExchangeRate::whereKey($rate->id)->lockForUpdate()->firstOrFail();
                if ($rate->active || Transfer::where('exchange_rate_id', $rate->id)->exists()) {
                    throw ValidationException::withMessages(['deletion' => $message]);
                }
                $rate->delete();
            }, 3);
        } catch (QueryException $exception) {
            if (in_array($exception->getCode(), ['23000', '23503'], true)) {
                throw ValidationException::withMessages(['deletion' => $message]);
            }
            throw $exception;
        }
    }
}
