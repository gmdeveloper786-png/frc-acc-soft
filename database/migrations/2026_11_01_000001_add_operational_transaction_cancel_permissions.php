<?php

use App\Enums\SystemRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** @var list<string> */
    private array $newPermissions = [
        'expenses.cancel',
        'donations.cancel',
        'receipts.cancel',
        'payments.cancel',
        'vendor_payments.cancel',
        'petty_cash.cancel',
    ];

    public function up(): void
    {
        $now = now();
        $ids = [];
        foreach ($this->newPermissions as $name) {
            $existing = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
            $ids[$name] = $existing ?: DB::table('permissions')->insertGetId([
                'name' => $name,
                'guard_name' => 'web',
                'group_name' => 'Operational Finance',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $rolesToGrant = [SystemRole::Administrator->value, SystemRole::Accountant->value];
        foreach ($rolesToGrant as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', 'web')->value('id');
            if (! $roleId) {
                continue;
            }
            foreach ($ids as $permId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permId,
                    'role_id' => $roleId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', $this->newPermissions)->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
