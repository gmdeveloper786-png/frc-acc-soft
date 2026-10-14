<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Director approval authority is a property of a role, separate from its
     * permissions, so a role that merely holds every permission does not gain it.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->string('approval_authority', 30)->nullable()->after('max_branches')->index();
        });
        DB::table('roles')->where('name', 'Director')->where('guard_name', 'web')->update(['approval_authority' => 'director']);

        Schema::create('approval_delegations', function (Blueprint $table): void {
            $table->id();
            $table->string('authority', 30);
            $table->foreignId('delegate_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('delegated_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('max_amount', 19, 4)->nullable();
            $table->text('reason');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['delegate_id', 'authority', 'ends_at']);
        });

        $now = now();
        if (! DB::table('app_settings')->where('key', 'approval.delegation_max_days')->exists()) {
            DB::table('app_settings')->insert(['key' => 'approval.delegation_max_days', 'value' => '14', 'type' => 'integer', 'group' => 'approvals', 'is_public' => false, 'created_at' => $now, 'updated_at' => $now]);
        }

        $permissionId = DB::table('permissions')->where('name', 'approval_delegations.manage')->where('guard_name', 'web')->value('id')
            ?: DB::table('permissions')->insertGetId(['name' => 'approval_delegations.manage', 'guard_name' => 'web', 'group_name' => 'Purchase Orders', 'created_at' => $now, 'updated_at' => $now]);
        foreach (DB::table('roles')->whereIn('name', ['Director', 'Administrator'])->where('guard_name', 'web')->pluck('id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->where('name', 'approval_delegations.manage')->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        DB::table('app_settings')->where('key', 'approval.delegation_max_days')->delete();
        Schema::dropIfExists('approval_delegations');
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropIndex(['approval_authority']);
            $table->dropColumn('approval_authority');
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
