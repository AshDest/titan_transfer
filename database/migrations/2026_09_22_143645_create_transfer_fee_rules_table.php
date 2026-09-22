<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_fee_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('source_branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('destination_branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->char('source_currency', 3);
            $table->decimal('percentage', 8, 4);
            $table->decimal('minimum_amount', 18, 2)->nullable();
            $table->decimal('maximum_amount', 18, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['source_branch_id', 'destination_branch_id', 'source_currency', 'active'], 'fee_rules_corridor');
            $table->index('effective_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_fee_rules');
    }
};
