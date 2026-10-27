<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers', 'beneficiaries'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('first_name', 255)->change());
        }
    }

    public function down(): void
    {
        foreach (['customers', 'beneficiaries'] as $name) {
            if (DB::table($name)->whereRaw('LENGTH(first_name) > 100')->exists()) {
                throw new RuntimeException('Cannot shorten name columns while longer names exist.');
            }
        }
        foreach (['customers', 'beneficiaries'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('first_name', 100)->change());
        }
    }
};
