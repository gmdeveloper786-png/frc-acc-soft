<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** @var array<string, list<string>> */
    private array $groups = [
        'Bank Reconciliation' => ['bank_reconciliation.view', 'bank_reconciliation.prepare', 'bank_reconciliation.approve'],
        'Petty Cash Imprest' => ['imprest.view', 'imprest.manage', 'imprest.replenish'],
        'Funds & Grants' => ['funds.view', 'funds.manage', 'grants.manage', 'fund_reports.view'],
        'Staff Advances' => ['staff_advances.view', 'staff_advances.manage', 'staff_advances.approve'],
    ];

    /**
     * Adds only the new permissions and grants them to the system roles. Existing
     * role assignments are never removed or re-applied.
     */
    public function up(): void
    {
        $now = now();
        $ids = [];
        foreach ($this->groups as $group => $names) {
            foreach ($names as $name) {
                $existing = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
                $ids[$name] = $existing ?: DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => 'web', 'group_name' => $group, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        $grants = ['Administrator' => array_keys($ids), ...RolePermissionSeeder::RELEASE_GRANTS];
        foreach ($grants as $roleName => $names) {
            $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', 'web')->value('id');
            if (! $roleId) {
                continue;
            }
            foreach ($names as $name) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $ids[$name], 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_merge(...array_values($this->groups)))->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
