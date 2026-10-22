<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class ExchangeRateLock
{
    /** Serialize configuration changes for a currency pair inside the caller's transaction. */
    public function acquire(string $from, string $to): void
    {
        $pair = ['from_currency' => $from, 'to_currency' => $to];
        DB::table('exchange_rate_locks')->insertOrIgnore($pair);
        DB::table('exchange_rate_locks')->where($pair)->lockForUpdate()->first();
    }
}
