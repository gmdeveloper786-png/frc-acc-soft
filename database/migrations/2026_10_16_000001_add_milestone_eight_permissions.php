<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** @var list<string> */
    private array $permissions = ['financial_statements.view', 'financial_statements.export', 'cash_flow.manage_classification', 'asset_reports.export', 'inventory_reports.export', 'budget_reports.export', 'payroll_reports.export'];

    /**
     * Adds only the new Milestone 8 reporting permissions. Existing role
     * assignments are never removed or re-applied.
     */
    public function up(): void
    {
        $now = now();
        $ids = [];
        foreach ($this->permissions as $name) {
            $existing = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
            $ids[$name] = $existing ?: DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => 'web', 'group_name' => 'Reports', 'created_at' => $now, 'updated_at' => $now]);
        }

        $exporters = ['financial_statements.export', 'asset_reports.export', 'inventory_reports.export', 'budget_reports.export'];
        $grants = [
            'Administrator' => $this->permissions,
            'Accountant' => ['financial_statements.view', ...$exporters, 'cash_flow.manage_classification', 'payroll_reports.export'],
            'Approver / Manager' => ['financial_statements.view'],
            'Director' => ['financial_statements.view', ...$exporters, 'payroll_reports.export'],
            'Management / Board' => ['financial_statements.view', ...$exporters],
            'Auditor' => ['financial_statements.view', ...$exporters, 'payroll_reports.export'],
        ];
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
        $ids = DB::table('permissions')->whereIn('name', $this->permissions)->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
