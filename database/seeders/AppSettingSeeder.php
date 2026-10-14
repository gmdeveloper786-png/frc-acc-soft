<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use Illuminate\Database\Seeder;

class AppSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            ['key' => 'organization.name', 'value' => config('accounting.organization.name'), 'type' => 'string', 'group' => 'organization', 'is_public' => true],
            ['key' => 'organization.short_name', 'value' => config('accounting.organization.short_name'), 'type' => 'string', 'group' => 'organization', 'is_public' => true],
            ['key' => 'organization.logo_path', 'value' => config('accounting.organization.logo_path'), 'type' => 'string', 'group' => 'organization', 'is_public' => true],
            ['key' => 'organization.address', 'value' => '', 'type' => 'string', 'group' => 'organization', 'is_public' => true],
            ['key' => 'organization.phone', 'value' => '', 'type' => 'string', 'group' => 'organization', 'is_public' => true],
            ['key' => 'organization.email', 'value' => '', 'type' => 'string', 'group' => 'organization', 'is_public' => true],
            ['key' => 'currency.code', 'value' => config('accounting.currency.code'), 'type' => 'string', 'group' => 'localization', 'is_public' => true],
            ['key' => 'currency.symbol', 'value' => config('accounting.currency.symbol'), 'type' => 'string', 'group' => 'localization', 'is_public' => true],
            ['key' => 'currency.decimals', 'value' => config('accounting.currency.decimals'), 'type' => 'integer', 'group' => 'localization', 'is_public' => true],
            ['key' => 'date.format', 'value' => config('accounting.date_format'), 'type' => 'string', 'group' => 'localization', 'is_public' => true],
            ['key' => 'app.timezone', 'value' => config('app.timezone'), 'type' => 'string', 'group' => 'localization', 'is_public' => false],
            ['key' => 'fiscal.start_month', 'value' => config('accounting.fiscal_year.start_month'), 'type' => 'integer', 'group' => 'fiscal', 'is_public' => false],
            ['key' => 'fiscal.start_day', 'value' => config('accounting.fiscal_year.start_day'), 'type' => 'integer', 'group' => 'fiscal', 'is_public' => false],
            ['key' => 'fiscal.initial_opening_date', 'value' => config('accounting.initial_opening_date'), 'type' => 'string', 'group' => 'fiscal', 'is_public' => false],
            ['key' => 'approval.purchase_order_threshold', 'value' => config('accounting.approvals.purchase_order_threshold'), 'type' => 'decimal', 'group' => 'approvals', 'is_public' => false],
            ['key' => 'approval.payment_threshold', 'value' => config('accounting.approvals.payment_threshold'), 'type' => 'decimal', 'group' => 'approvals', 'is_public' => false],
            ['key' => 'approval.purchase_order_standard_policy', 'value' => config('accounting.approvals.purchase_order_standard_policy'), 'type' => 'string', 'group' => 'approvals', 'is_public' => false],
            ['key' => 'approval.delegation_enabled', 'value' => '0', 'type' => 'boolean', 'group' => 'approvals', 'is_public' => false],
            ['key' => 'approval.delegation_max_days', 'value' => config('accounting.approvals.delegation_max_days'), 'type' => 'integer', 'group' => 'approvals', 'is_public' => false],
            ['key' => 'numbering.purchase_order_prefix', 'value' => config('accounting.numbering.purchase_order_prefix'), 'type' => 'string', 'group' => 'numbering', 'is_public' => false],
            ['key' => 'numbering.purchase_order_padding', 'value' => config('accounting.numbering.purchase_order_padding'), 'type' => 'integer', 'group' => 'numbering', 'is_public' => false],
            ['key' => 'numbering.purchase_order_reset', 'value' => config('accounting.numbering.purchase_order_reset'), 'type' => 'string', 'group' => 'numbering', 'is_public' => false],
            ['key' => 'approval.split_detection_days', 'value' => '7', 'type' => 'integer', 'group' => 'approvals', 'is_public' => false],
            ['key' => 'assets.depreciation_policy_approved', 'value' => '0', 'type' => 'integer', 'group' => 'assets', 'is_public' => false],
            ['key' => 'assets.depreciation_convention', 'value' => 'full_month', 'type' => 'string', 'group' => 'assets', 'is_public' => false],
            ['key' => 'assets.capitalization_threshold', 'value' => '0.00', 'type' => 'decimal', 'group' => 'assets', 'is_public' => false],
            ['key' => 'payroll.calculation_policy_approved', 'value' => '0', 'type' => 'integer', 'group' => 'payroll', 'is_public' => false],
            ['key' => 'inventory.allow_negative_stock', 'value' => '0', 'type' => 'integer', 'group' => 'inventory', 'is_public' => false],
            ['key' => 'fees.accounting_basis', 'value' => 'cash', 'type' => 'string', 'group' => 'fees', 'is_public' => false],
            ['key' => 'fees.accounting_policy_approved', 'value' => '0', 'type' => 'integer', 'group' => 'fees', 'is_public' => false],
            ['key' => 'budget.warning_percentage', 'value' => config('accounting.budget_warning_percentage'), 'type' => 'integer', 'group' => 'budget', 'is_public' => false],
        ];

        foreach ($settings as $setting) {
            AppSetting::query()->firstOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
