<?php

namespace App\Actions\Finance;

use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\ExchangeRateLock;
use App\Services\OperationNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SetExchangeRateStatus
{
    public function handle(User $actor, ExchangeRate $rate, bool $active, bool $notify = true): ExchangeRate
    {
        Gate::forUser($actor)->authorize('activate', $rate);

        return DB::transaction(function () use ($actor, $rate, $active, $notify): ExchangeRate {
            app(ExchangeRateLock::class)->acquire($rate->from_currency, $rate->to_currency);
            $rate = ExchangeRate::whereKey($rate->id)->lockForUpdate()->firstOrFail();
            $at = now('UTC');
            if ($active && ($rate->effective_from->greaterThan($at) || $rate->effective_until?->lessThanOrEqualTo($at))) {
                throw ValidationException::withMessages(['activation' => 'Ce taux est futur ou expiré. Modifiez ses dates dans une nouvelle version avant de l’activer.']);
            }

            $changed = $rate->active !== $active;
            if ($active) {
                $others = ExchangeRate::where('from_currency', $rate->from_currency)
                    ->where('to_currency', $rate->to_currency)->whereKeyNot($rate->id)
                    ->where('active', true)->orderBy('id')->lockForUpdate()->get();
                foreach ($others as $other) {
                    $other->active = false;
                    $other->save();
                }
                $changed = $changed || $others->isNotEmpty();
            }
            $rate->active = $active;
            $rate->save();
            if ($changed && $notify) {
                app(OperationNotifications::class)->record($rate, $actor, $active ? 'activated' : 'deactivated');
            }

            return $rate;
        }, 3);
    }
}
