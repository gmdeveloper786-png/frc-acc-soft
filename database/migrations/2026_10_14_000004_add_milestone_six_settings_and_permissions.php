<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** @var array<string, list<string>> */
    private array $permissions = [
        'Accounting' => ['account_mappings.view', 'account_mappings.manage', 'accounting_setup.readiness'],
        'Purchase Orders' => ['purchase_orders.update', 'purchase_orders.submit', 'purchase_orders.approve_high_value', 'purchase_orders.reject', 'purchase_orders.issue', 'purchase_orders.close', 'purchase_orders.cancel', 'purchase_orders.view_history'],
        'Operational Finance' => ['purchase_bills.approve_po_exception'],
    ];

    /** @var array<string, list<string>> */
    private array $roleGrants = [
        'Accountant' => ['account_mappings.view', 'account_mappings.manage', 'purchase_orders.update', 'purchase_orders.submit', 'purchase_orders.issue', 'purchase_orders.close', 'purchase_orders.view_history'],
        'Approver / Manager' => ['account_mappings.view', 'purchase_orders.reject', 'purchase_orders.view_history'],
        'Director' => ['account_mappings.view', 'purchase_orders.approve_high_value', 'purchase_orders.reject', 'purchase_orders.close', 'purchase_orders.cancel', 'purchase_orders.view_history', 'purchase_bills.approve_po_exception'],
        'Management / Board' => ['account_mappings.view', 'purchase_orders.view_history'],
        'Auditor' => ['account_mappings.view', 'purchase_orders.view_history'],
    ];

    /**
     * Adds only the new Milestone 6 settings and permissions. Existing role
     * assignments are never removed or re-applied.
     */
    public function up(): void
    {
        $now = now();
        $settings = [
            ['key' => 'approval.purchase_order_standard_policy', 'value' => 'authorized_approver', 'type' => 'string', 'group' => 'approvals'],
            ['key' => 'numbering.purchase_order_prefix', 'value' => 'PO', 'type' => 'string', 'group' => 'numbering'],
            ['key' => 'numbering.purchase_order_padding', 'value' => '5', 'type' => 'integer', 'group' => 'numbering'],
            ['key' => 'numbering.purchase_order_reset', 'value' => 'yearly', 'type' => 'string', 'group' => 'numbering'],
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

        $grants = [...$this->roleGrants, 'Administrator' => array_keys($ids)];
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
        $names = collect($this->permissions)->flatten()->all();
        $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        DB::table('app_settings')->whereIn('key', ['approval.purchase_order_standard_policy', 'numbering.purchase_order_prefix', 'numbering.purchase_order_padding', 'numbering.purchase_order_reset'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
