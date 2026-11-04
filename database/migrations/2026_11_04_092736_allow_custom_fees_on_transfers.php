<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table): void {
            $table->foreignId('transfer_fee_rule_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('transfers')->whereNull('transfer_fee_rule_id')->exists()) {
            throw new RuntimeException('Cannot require a fee rule while transfers with custom fees exist.');
        }
        Schema::table('transfers', function (Blueprint $table): void {
            $table->foreignId('transfer_fee_rule_id')->nullable(false)->change();
        });
    }
};
