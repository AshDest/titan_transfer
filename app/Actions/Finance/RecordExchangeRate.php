<?php

namespace App\Actions\Finance;

use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\ExchangeRateLock;
use App\Services\OperationNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class RecordExchangeRate
{
    /** @param array<string, mixed> $input */
    public function handle(User $actor, array $input): ExchangeRate
    {
        Gate::forUser($actor)->authorize('create', ExchangeRate::class);

        $data = Validator::make($input, [
            'from_currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'to_currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/', 'different:from_currency'],
            'rate' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,11})(\.[0-9]{1,6})?$/', 'numeric', 'gt:0'],
            'effective_from' => ['required', 'date_format:Y-m-d H:i:s'],
            'effective_until' => ['nullable', 'date_format:Y-m-d H:i:s', 'after:effective_from'],
            'active' => ['sometimes', 'boolean'],
        ])->validate();

        return DB::transaction(function () use ($actor, $data): ExchangeRate {
            app(ExchangeRateLock::class)->acquire($data['from_currency'], $data['to_currency']);
            $activate = (bool) ($data['active'] ?? true);
            $rate = new ExchangeRate([...$data, 'active' => false]);
            $rate->createdBy()->associate($actor);
            $rate->save();

            $rate->refresh();
            if ($activate) {
                $rate = app(SetExchangeRateStatus::class)->handle($actor, $rate, true, false);
            }
            app(OperationNotifications::class)->record($rate, $actor);

            return $rate;
        });
    }
}
