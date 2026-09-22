<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** SQLite NUMERIC affinity uses binary floats; decimal strings preserve exact values locally. */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }
        Schema::table('exchange_rates', fn (Blueprint $table) => $table->string('rate', 25)->change());
        Schema::table('transfer_fee_rules', function (Blueprint $table): void {
            $table->string('percentage', 12)->change();
            $table->string('minimum_amount', 24)->nullable()->change();
            $table->string('maximum_amount', 24)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }
        Schema::table('exchange_rates', fn (Blueprint $table) => $table->decimal('rate', 18, 6)->change());
        Schema::table('transfer_fee_rules', function (Blueprint $table): void {
            $table->decimal('percentage', 8, 4)->change();
            $table->decimal('minimum_amount', 18, 2)->nullable()->change();
            $table->decimal('maximum_amount', 18, 2)->nullable()->change();
        });
    }
};
