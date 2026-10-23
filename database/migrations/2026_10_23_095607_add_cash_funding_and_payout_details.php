<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->string('provider_name')->nullable();
        });
        Schema::table('transfers', function (Blueprint $table): void {
            $table->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('paid_at')->nullable();
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('cash.deposit', 'web');
        foreach (Role::where('guard_name', 'web')->whereIn('name', ['administrator', 'destination-cashier'])->get() as $role) {
            $role->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::table('cash_movements')->whereNotNull('provider_name')->exists() || DB::table('transfers')->whereNotNull('paid_at')->exists()) {
            throw new RuntimeException('Cannot remove funding and payout history while recorded operations exist.');
        }
        Schema::table('transfers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('paid_by');
            $table->dropColumn('paid_at');
        });
        Schema::table('cash_movements', fn (Blueprint $table) => $table->dropColumn('provider_name'));
        Permission::where('name', 'cash.deposit')->where('guard_name', 'web')->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
