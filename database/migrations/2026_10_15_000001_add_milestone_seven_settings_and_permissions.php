<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** @var array<string, list<string>> */
    private array $permissions = [
        'Fixed Assets' => ['asset_categories.view', 'asset_categories.manage', 'fixed_assets.view', 'fixed_assets.create', 'fixed_assets.update', 'fixed_assets.retire', 'depreciation.view', 'depreciation.prepare', 'depreciation.approve', 'depreciation.post', 'depreciation.reverse'],
        'Inventory' => ['inventory.view', 'inventory_items.manage', 'inventory.receive', 'inventory.issue', 'inventory.return', 'inventory.adjust', 'inventory.transfer', 'inventory.approve_adjustment'],
        'Budgets' => ['budgets.view', 'budgets.create', 'budgets.update', 'budgets.submit', 'budgets.approve', 'budgets.revise', 'budget_reports.view'],
        'Payroll' => ['payroll.view_sensitive', 'payroll.import', 'payroll.submit', 'payroll.post', 'payroll.pay', 'payroll_components.manage', 'employees.view', 'employees.manage'],
    ];

    /** @var list<string> */
    private array $readOnly = ['asset_categories.view', 'fixed_assets.view', 'depreciation.view', 'inventory.view', 'budgets.view', 'budget_reports.view', 'employees.view', 'payroll.view_sensitive'];

    /**
     * Adds only the new Milestone 7 settings and permissions. Existing role
     * assignments are never removed or re-applied.
     */
    public function up(): void
    {
        $now = now();
        $settings = [
            ['key' => 'approval.split_detection_days', 'value' => '7', 'type' => 'integer', 'group' => 'approvals'],
            ['key' => 'assets.depreciation_policy_approved', 'value' => '0', 'type' => 'integer', 'group' => 'assets'],
            ['key' => 'assets.depreciation_convention', 'value' => 'full_month', 'type' => 'string', 'group' => 'assets'],
            ['key' => 'assets.capitalization_threshold', 'value' => '0.00', 'type' => 'decimal', 'group' => 'assets'],
            ['key' => 'payroll.calculation_policy_approved', 'value' => '0', 'type' => 'integer', 'group' => 'payroll'],
            ['key' => 'inventory.allow_negative_stock', 'value' => '0', 'type' => 'integer', 'group' => 'inventory'],
        ];
        foreach ($settings as $setting) {
            if (! DB::table('app_settings')->where('key', $setting['key'])->exists()) {
                DB::table('app_settings')->insert([...$setting, 'is_public' => false, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        $ids = [];
        foreach ($this->permissions as $group => $names) {
            foreach ($names as $name) {
                $existing = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
                $ids[$name] = $existing ?: DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => 'web', 'group_name' => $group, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        $grants = [
            'Administrator' => array_keys($ids),
            'Accountant' => [...$this->readOnly, 'asset_categories.manage', 'fixed_assets.create', 'fixed_assets.update', 'fixed_assets.retire', 'depreciation.prepare', 'depreciation.post', 'depreciation.reverse', 'inventory_items.manage', 'inventory.receive', 'inventory.issue', 'inventory.return', 'inventory.adjust', 'inventory.transfer', 'budgets.create', 'budgets.update', 'budgets.submit', 'budgets.revise', 'payroll.import', 'payroll.submit', 'payroll.post', 'payroll.pay', 'payroll_components.manage', 'employees.manage'],
            'Approver / Manager' => [...$this->readOnly, 'depreciation.approve', 'inventory.approve_adjustment', 'budgets.approve'],
            'Director' => [...$this->readOnly, 'depreciation.approve', 'inventory.approve_adjustment', 'budgets.approve'],
            'Management / Board' => $this->readOnly,
            'Auditor' => $this->readOnly,
        ];
        // Existing payroll permissions extended to the approving and read-only roles.
        $existing = DB::table('permissions')->whereIn('name', ['payroll.view', 'payroll.approve'])->where('guard_name', 'web')->pluck('id', 'name');
        $extra = ['Approver / Manager' => ['payroll.view', 'payroll.approve'], 'Director' => ['payroll.view', 'payroll.approve'], 'Management / Board' => ['payroll.view'], 'Auditor' => ['payroll.view']];

        foreach ($grants as $roleName => $names) {
            $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', 'web')->value('id');
            if (! $roleId) {
                continue;
            }
            foreach ($names as $name) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $ids[$name], 'role_id' => $roleId]);
            }
            foreach ($extra[$roleName] ?? [] as $name) {
                if (isset($existing[$name])) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $existing[$name], 'role_id' => $roleId]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', collect($this->permissions)->flatten()->all())->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        DB::table('app_settings')->whereIn('key', ['approval.split_detection_days', 'assets.depreciation_policy_approved', 'assets.depreciation_convention', 'assets.capitalization_threshold', 'payroll.calculation_policy_approved', 'inventory.allow_negative_stock'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
