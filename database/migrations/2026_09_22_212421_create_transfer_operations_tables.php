<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->char('currency', 3);
            $this->decimal($table, 'opening_balance')->default('0.00');
            $this->decimal($table, 'balance')->default('0.00');
            $table->string('status', 16)->default('ACTIVE');
            $table->timestamps();
            $table->unique(['branch_id', 'currency']);
        });
        Schema::create('transfers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference', 64)->unique();
            $table->uuid('submission_key')->unique();
            $table->foreignId('sender_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('beneficiary_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('destination_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('exchange_rate_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('transfer_fee_rule_id')->constrained()->restrictOnDelete();
            $table->char('source_currency', 3);
            $table->char('destination_currency', 3);
            $this->decimal($table, 'amount_received');
            $this->decimal($table, 'fee_percentage', 8, 4);
            $this->decimal($table, 'fee_amount');
            $this->decimal($table, 'net_amount');
            $this->decimal($table, 'exchange_rate', 18, 6);
            $this->decimal($table, 'payout_amount');
            $table->json('parties_snapshot');
            $table->string('status', 24)->default('PENDING');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['source_branch_id', 'status', 'created_at']);
            $table->index(['destination_branch_id', 'status', 'created_at']);
            $table->index('sender_id');
            $table->index('beneficiary_id');
        });
        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('transfer_id')->constrained()->restrictOnDelete();
            $table->string('type', 24);
            $table->char('currency', 3);
            $this->decimal($table, 'amount');
            $this->decimal($table, 'balance_before');
            $this->decimal($table, 'balance_after');
            $table->string('description');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['transfer_id', 'type']);
            $table->index(['cash_account_id', 'created_at']);
        });
        Schema::create('transfer_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transfer_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action');
            $table->json('old_values')->nullable();
            $table->json('new_values');
            $table->ipAddress('ip_address')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['transfer_id', 'created_at']);
        });
    }

    private function decimal(Blueprint $table, string $name, int $precision = 18, int $scale = 2): ColumnDefinition
    {
        return DB::getDriverName() === 'sqlite'
            ? $table->string($name, $precision + 2)
            : $table->decimal($name, $precision, $scale);
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_events');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('transfers');
        Schema::dropIfExists('cash_accounts');
    }
};
