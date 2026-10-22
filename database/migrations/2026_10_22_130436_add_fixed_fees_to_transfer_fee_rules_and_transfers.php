<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_fee_rules', function (Blueprint $table): void {
            $table->string('fee_type', 16)->default('percentage');
            if (DB::getDriverName() === 'sqlite') {
                $table->string('fixed_amount', 20)->nullable();
            } else {
                $table->decimal('fixed_amount', 18, 2)->nullable();
            }
        });
        Schema::table('transfers', function (Blueprint $table): void {
            $table->string('fee_type', 16)->default('percentage');
        });
    }

    public function down(): void
    {
        if (DB::table('transfers')->where('fee_type', 'fixed')->exists()
            || DB::table('transfer_fee_rules')->where('fee_type', 'fixed')->exists()) {
            throw new RuntimeException('Cannot remove fixed fees while fixed-fee records exist.');
        }
        Schema::table('transfers', fn (Blueprint $table) => $table->dropColumn('fee_type'));
        Schema::table('transfer_fee_rules', fn (Blueprint $table) => $table->dropColumn(['fee_type', 'fixed_amount']));
    }
};
