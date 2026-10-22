<?php

use Carbon\CarbonImmutable;
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
            $table->foreignId('transfer_id')->nullable()->change();
            $table->date('occurred_on')->nullable()->index();
            $table->string('recipient_name')->nullable();
            $table->uuid('submission_key')->nullable()->unique();
        });

        DB::table('cash_movements as movements')->join('cash_accounts', 'cash_accounts.id', '=', 'movements.cash_account_id')
            ->join('branches', 'branches.id', '=', 'cash_accounts.branch_id')
            ->select('movements.id', 'movements.created_at', 'branches.timezone')
            ->chunkById(200, function ($movements): void {
                foreach ($movements as $movement) {
                    DB::table('cash_movements')->where('id', $movement->id)->update([
                        'occurred_on' => CarbonImmutable::parse($movement->created_at, 'UTC')->setTimezone($movement->timezone)->toDateString(),
                    ]);
                }
            }, 'movements.id', 'id');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('cash.withdraw', 'web');
        foreach (Role::where('guard_name', 'web')->whereIn('name', ['administrator', 'source-cashier', 'destination-cashier'])->get() as $role) {
            $role->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::table('cash_movements')->whereNull('transfer_id')->exists()) {
            throw new RuntimeException('Cannot remove cash-out fields while manual cash movements exist.');
        }
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->dropUnique(['submission_key']);
            $table->dropIndex(['occurred_on']);
            $table->dropColumn(['occurred_on', 'recipient_name', 'submission_key']);
            $table->foreignId('transfer_id')->nullable(false)->change();
        });
        Permission::where('name', 'cash.withdraw')->where('guard_name', 'web')->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
