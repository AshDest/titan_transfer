<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_movement_id')->unique()->constrained()->restrictOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->index(['period_from', 'period_to']);
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('fee_withdrawal_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fee_withdrawal_id')->constrained()->restrictOnDelete();
            $table->foreignId('transfer_id')->constrained()->restrictOnDelete();
            if (DB::getDriverName() === 'sqlite') {
                $table->string('amount', 20);
            } else {
                $table->decimal('amount', 18, 2);
            }
            $table->unique(['fee_withdrawal_id', 'transfer_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('fee_withdrawals')->exists()) {
            throw new RuntimeException('Cannot remove recorded fee withdrawals.');
        }
        Schema::dropIfExists('fee_withdrawal_items');
        Schema::dropIfExists('fee_withdrawals');
    }
};
