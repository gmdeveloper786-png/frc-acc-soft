<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Permission for changing approval thresholds and policies. It is only
     * effective together with Director authority.
     */
    public function up(): void
    {
        $now = now();
        $permissionId = DB::table('permissions')->where('name', 'approval_settings.manage')->where('guard_name', 'web')->value('id')
            ?: DB::table('permissions')->insertGetId(['name' => 'approval_settings.manage', 'guard_name' => 'web', 'group_name' => 'Settings', 'created_at' => $now, 'updated_at' => $now]);
        foreach (DB::table('roles')->whereIn('name', ['Director', 'Administrator'])->where('guard_name', 'web')->pluck('id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->where('name', 'approval_settings.manage')->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
