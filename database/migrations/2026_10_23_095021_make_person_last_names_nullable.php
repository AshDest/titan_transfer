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
            Schema::table($name, function (Blueprint $table): void {
                $table->string('last_name', 100)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['customers', 'beneficiaries'] as $name) {
            if (DB::table($name)->whereNull('last_name')->exists()) {
                throw new RuntimeException('Cannot require last names while people without a last name exist.');
            }
        }
        foreach (['customers', 'beneficiaries'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('last_name', 100)->nullable(false)->change();
            });
        }
    }
};
