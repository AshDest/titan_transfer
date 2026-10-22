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
            $table->timestamp('deleted_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (DB::table('transfer_fee_rules')->whereNotNull('deleted_at')->exists()) {
            throw new RuntimeException('Cannot restore deleted fee rules by rolling back their removal history.');
        }
        Schema::table('transfer_fee_rules', function (Blueprint $table): void {
            $table->dropIndex(['deleted_at']);
            $table->dropColumn('deleted_at');
        });
    }
};
