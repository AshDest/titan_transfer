<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('from_currency', 3);
            $table->char('to_currency', 3);
            $table->decimal('rate', 18, 6);
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['from_currency', 'to_currency', 'active', 'effective_from'], 'exchange_rates_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
