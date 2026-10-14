<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Director delegation is switched off until the owner approves it, and a
     * dedicated permission is added for changing who holds Director authority.
     */
    public function up(): void
    {
        $now = now();
        if (! DB::table('app_settings')->where('key', 'approval.delegation_enabled')->exists()) {
            DB::table('app_settings')->insert(['key' => 'approval.delegation_enabled', 'value' => '0', 'type' => 'boolean', 'group' => 'approvals', 'is_public' => false, 'created_at' => $now, 'updated_at' => $now]);
        }

        $permissionId = DB::table('permissions')->where('name', 'approval_authority.assign')->where('guard_name', 'web')->value('id')
            ?: DB::table('permissions')->insertGetId(['name' => 'approval_authority.assign', 'guard_name' => 'web', 'group_name' => 'Users', 'created_at' => $now, 'updated_at' => $now]);
        foreach (DB::table('roles')->whereIn('name', ['Director', 'Administrator'])->where('guard_name', 'web')->pluck('id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->where('name', 'approval_authority.assign')->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        DB::table('app_settings')->where('key', 'approval.delegation_enabled')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
