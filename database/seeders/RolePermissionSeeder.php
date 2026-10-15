<?php

namespace Database\Seeders;

use App\Enums\SystemRole;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ApprovalAuthorityService;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /** @var array<string, list<string>> Grants for bank reconciliation, imprest, funds and staff advances. */
    public const RELEASE_GRANTS = [
        'Accountant' => ['bank_reconciliation.view', 'bank_reconciliation.prepare', 'imprest.view', 'imprest.manage', 'imprest.replenish', 'funds.view', 'funds.manage', 'grants.manage', 'fund_reports.view', 'staff_advances.view', 'staff_advances.manage'],
        'Cashier / Fee Clerk' => ['imprest.view'],
        'Approver / Manager' => ['bank_reconciliation.view', 'bank_reconciliation.approve', 'imprest.view', 'funds.view', 'fund_reports.view', 'staff_advances.view', 'staff_advances.approve'],
        'Director' => ['bank_reconciliation.view', 'bank_reconciliation.approve', 'imprest.view', 'funds.view', 'fund_reports.view', 'staff_advances.view', 'staff_advances.approve'],
        'Management / Board' => ['bank_reconciliation.view', 'funds.view', 'fund_reports.view'],
        'Auditor' => ['bank_reconciliation.view', 'imprest.view', 'funds.view', 'fund_reports.view', 'staff_advances.view'],
    ];

    /** @var array<string, list<string>> */
    private array $groups = [
        'Dashboard' => ['dashboard.view'],
        'Users' => ['users.view', 'users.create', 'users.update', 'users.deactivate', 'users.assign_roles', 'approval_authority.assign'],
        'Roles' => ['roles.view', 'roles.create', 'roles.update', 'roles.assign_permissions'],
        'Branches' => ['branches.view', 'branches.view_all', 'branches.sync', 'branches.assign_users'],
        'Services' => ['services.view', 'services.sync'],
        'Settings' => ['settings.view', 'settings.update', 'approval_settings.manage'],
        'Fiscal setup' => ['fiscal_years.view', 'fiscal_years.manage', 'fiscal_years.close', 'fiscal_years.reopen', 'accounting_periods.view', 'accounting_periods.manage', 'accounting_periods.close', 'accounting_periods.reopen'],
        'Accounting' => ['accounts.view', 'accounts.manage', 'accounts.create', 'accounts.update', 'accounts.import', 'accounts.deactivate', 'voucher_types.view', 'voucher_types.manage', 'opening_balances.view', 'opening_balances.prepare', 'opening_balances.import', 'opening_balances.approve', 'opening_balances.post', 'journals.view', 'journals.create', 'journals.update', 'journals.submit', 'journals.approve', 'journals.reject', 'journals.post', 'journals.reverse', 'ledgers.view', 'trial_balance.view', 'account_mappings.view', 'account_mappings.manage', 'accounting_setup.readiness'],
        'Operational Finance' => [
            'purchase_bills.approve_po_exception',
            'financial_accounts.view', 'financial_accounts.manage',
            'receipts.view', 'receipts.create', 'receipts.post', 'receipts.reverse',
            'payments.view', 'payments.create', 'payments.update', 'payments.submit', 'payments.approve', 'payments.approve_high_value', 'payments.disburse', 'payments.post', 'payments.reverse',
            'expenses.view', 'expenses.create', 'expenses.update', 'expenses.submit', 'expenses.approve', 'expenses.disburse', 'expenses.post', 'expenses.reverse',
            'vendors.view', 'vendors.create', 'vendors.update', 'vendors.deactivate',
            'purchase_bills.view', 'purchase_bills.create', 'purchase_bills.update', 'purchase_bills.submit', 'purchase_bills.approve', 'purchase_bills.post', 'purchase_bills.reverse',
            'vendor_payments.view', 'vendor_payments.create', 'vendor_payments.submit', 'vendor_payments.approve', 'vendor_payments.approve_high_value', 'vendor_payments.disburse', 'vendor_payments.post', 'vendor_payments.reverse',
            'petty_cash.view', 'petty_cash.create', 'petty_cash.update', 'petty_cash.submit', 'petty_cash.approve', 'petty_cash.disburse', 'petty_cash.post', 'petty_cash.reverse',
            'donations.view', 'donations.create', 'donations.post', 'donations.reverse', 'donations.manage_categories',
            'cash_books.view', 'bank_books.view', 'finance_reports.view', 'finance_reports.export',
        ],
        'Purchase Orders' => ['purchase_orders.view', 'purchase_orders.create', 'purchase_orders.approve', 'purchase_orders.update', 'purchase_orders.submit', 'purchase_orders.approve_high_value', 'purchase_orders.reject', 'purchase_orders.issue', 'purchase_orders.close', 'purchase_orders.cancel', 'purchase_orders.view_history', 'approval_delegations.manage'],
        'Reports' => ['reports.view', 'reports.export', 'financial_statements.view', 'financial_statements.export', 'cash_flow.manage_classification', 'asset_reports.export', 'inventory_reports.export', 'budget_reports.export', 'payroll_reports.export'],
        'Fixed Assets' => ['asset_categories.view', 'asset_categories.manage', 'fixed_assets.view', 'fixed_assets.create', 'fixed_assets.update', 'fixed_assets.retire', 'depreciation.view', 'depreciation.prepare', 'depreciation.approve', 'depreciation.post', 'depreciation.reverse'],
        'Inventory' => ['inventory.view', 'inventory_items.manage', 'inventory.receive', 'inventory.issue', 'inventory.return', 'inventory.adjust', 'inventory.transfer', 'inventory.approve_adjustment'],
        'Budgets' => ['budgets.view', 'budgets.create', 'budgets.update', 'budgets.submit', 'budgets.approve', 'budgets.revise', 'budget_reports.view'],
        'Payroll' => ['payroll.view', 'payroll.manage', 'payroll.approve', 'payroll.view_sensitive', 'payroll.import', 'payroll.submit', 'payroll.post', 'payroll.pay', 'payroll_components.manage', 'employees.view', 'employees.manage'],
        'Audit' => ['audit_logs.view'],
        'FRC Integration' => ['frc_integration.view', 'frc_integration.sync', 'frc_integration.post', 'frc_integration.reverse', 'fee_settlement.manage'],
        'Bank Reconciliation' => ['bank_reconciliation.view', 'bank_reconciliation.prepare', 'bank_reconciliation.approve'],
        'Petty Cash Imprest' => ['imprest.view', 'imprest.manage', 'imprest.replenish'],
        'Funds & Grants' => ['funds.view', 'funds.manage', 'grants.manage', 'fund_reports.view'],
        'Staff Advances' => ['staff_advances.view', 'staff_advances.manage', 'staff_advances.approve'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->groups as $group => $names) {
            foreach ($names as $name) {
                Permission::query()->updateOrCreate(
                    ['name' => $name, 'guard_name' => 'web'],
                    ['group_name' => $group],
                );
            }
        }

        $roles = [
            SystemRole::Administrator->value => ['permissions' => Permission::query()->pluck('name')->all(), 'protected' => true],
            SystemRole::Accountant->value => ['permissions' => ['dashboard.view', 'branches.view', 'services.view', 'fiscal_years.view', 'fiscal_years.manage', 'accounting_periods.view', 'accounting_periods.manage', 'accounts.view', 'accounts.manage', 'accounts.create', 'accounts.update', 'accounts.import', 'accounts.deactivate', 'voucher_types.view', 'opening_balances.view', 'opening_balances.prepare', 'opening_balances.import', 'opening_balances.post', 'journals.view', 'journals.create', 'journals.update', 'journals.submit', 'journals.post', 'journals.reverse', 'ledgers.view', 'trial_balance.view', 'payments.view', 'payments.create', 'payments.post', 'purchase_orders.view', 'purchase_orders.create', 'reports.view', 'reports.export', 'payroll.view', 'payroll.manage']],
            SystemRole::Cashier->value => ['permissions' => ['dashboard.view', 'branches.view', 'services.view', 'payments.view', 'payments.create'], 'max_branches' => 1],
            SystemRole::Approver->value => ['permissions' => ['dashboard.view', 'branches.view', 'services.view', 'fiscal_years.view', 'accounting_periods.view', 'accounting_periods.close', 'accounts.view', 'opening_balances.view', 'opening_balances.approve', 'journals.view', 'journals.approve', 'journals.reject', 'payments.view', 'payments.approve', 'purchase_orders.view', 'purchase_orders.approve', 'reports.view']],
            SystemRole::Director->value => ['permissions' => ['dashboard.view', 'branches.view', 'services.view', 'fiscal_years.view', 'fiscal_years.close', 'fiscal_years.reopen', 'accounting_periods.view', 'accounting_periods.close', 'accounting_periods.reopen', 'accounts.view', 'voucher_types.view', 'opening_balances.view', 'opening_balances.approve', 'journals.view', 'journals.approve', 'journals.reject', 'journals.post', 'journals.reverse', 'ledgers.view', 'trial_balance.view', 'payments.view', 'payments.approve', 'purchase_orders.view', 'purchase_orders.approve', 'reports.view', 'reports.export']],
            SystemRole::Management->value => ['permissions' => ['dashboard.view', 'branches.view', 'services.view', 'fiscal_years.view', 'accounting_periods.view', 'accounts.view', 'voucher_types.view', 'opening_balances.view', 'journals.view', 'ledgers.view', 'trial_balance.view', 'payments.view', 'purchase_orders.view', 'reports.view', 'reports.export']],
            SystemRole::Auditor->value => ['permissions' => ['dashboard.view', 'branches.view', 'services.view', 'fiscal_years.view', 'accounting_periods.view', 'accounts.view', 'voucher_types.view', 'opening_balances.view', 'journals.view', 'ledgers.view', 'trial_balance.view', 'payments.view', 'purchase_orders.view', 'reports.view', 'reports.export', 'audit_logs.view']],
        ];

        $roles[SystemRole::Accountant->value]['permissions'] = array_merge($roles[SystemRole::Accountant->value]['permissions'], [
            'financial_accounts.view', 'financial_accounts.manage', 'receipts.view', 'receipts.create', 'receipts.post', 'receipts.reverse',
            'payments.update', 'payments.submit', 'payments.disburse', 'payments.reverse', 'expenses.view', 'expenses.create', 'expenses.update', 'expenses.submit', 'expenses.disburse', 'expenses.post', 'expenses.reverse',
            'vendors.view', 'vendors.create', 'vendors.update', 'vendors.deactivate', 'purchase_bills.view', 'purchase_bills.create', 'purchase_bills.update', 'purchase_bills.submit', 'purchase_bills.post', 'purchase_bills.reverse',
            'vendor_payments.view', 'vendor_payments.create', 'vendor_payments.submit', 'vendor_payments.disburse', 'vendor_payments.post', 'vendor_payments.reverse',
            'petty_cash.view', 'petty_cash.create', 'petty_cash.update', 'petty_cash.submit', 'petty_cash.disburse', 'petty_cash.post', 'petty_cash.reverse',
            'donations.view', 'donations.create', 'donations.post', 'donations.reverse', 'donations.manage_categories', 'cash_books.view', 'bank_books.view', 'finance_reports.view', 'finance_reports.export',
        ]);
        $roles[SystemRole::Cashier->value]['permissions'] = array_merge($roles[SystemRole::Cashier->value]['permissions'], ['financial_accounts.view', 'receipts.view', 'receipts.create', 'payments.submit', 'expenses.view', 'expenses.create', 'expenses.submit', 'vendor_payments.view', 'vendor_payments.create', 'vendor_payments.submit', 'petty_cash.view', 'petty_cash.create', 'petty_cash.submit', 'donations.view', 'donations.create']);
        $roles[SystemRole::Approver->value]['permissions'] = array_merge($roles[SystemRole::Approver->value]['permissions'], ['financial_accounts.view', 'receipts.view', 'expenses.view', 'expenses.approve', 'vendors.view', 'purchase_bills.view', 'purchase_bills.approve', 'vendor_payments.view', 'vendor_payments.approve', 'petty_cash.view', 'petty_cash.approve', 'donations.view', 'cash_books.view', 'bank_books.view', 'finance_reports.view']);
        $roles[SystemRole::Director->value]['permissions'] = array_merge($roles[SystemRole::Director->value]['permissions'], ['financial_accounts.view', 'receipts.view', 'payments.approve_high_value', 'expenses.view', 'expenses.approve', 'vendors.view', 'purchase_bills.view', 'purchase_bills.approve', 'vendor_payments.view', 'vendor_payments.approve', 'vendor_payments.approve_high_value', 'petty_cash.view', 'petty_cash.approve', 'donations.view', 'cash_books.view', 'bank_books.view', 'finance_reports.view', 'finance_reports.export']);
        foreach ([SystemRole::Management->value, SystemRole::Auditor->value] as $readOnlyRole) {
            $roles[$readOnlyRole]['permissions'] = array_merge($roles[$readOnlyRole]['permissions'], ['financial_accounts.view', 'receipts.view', 'expenses.view', 'vendors.view', 'purchase_bills.view', 'vendor_payments.view', 'petty_cash.view', 'donations.view', 'cash_books.view', 'bank_books.view', 'finance_reports.view', 'finance_reports.export']);
        }

        // Milestone 6: GL mappings and the purchase order workflow.
        $milestoneSix = [
            SystemRole::Accountant->value => ['account_mappings.view', 'account_mappings.manage', 'purchase_orders.update', 'purchase_orders.submit', 'purchase_orders.issue', 'purchase_orders.close', 'purchase_orders.view_history'],
            SystemRole::Approver->value => ['account_mappings.view', 'purchase_orders.reject', 'purchase_orders.view_history'],
            SystemRole::Director->value => ['account_mappings.view', 'purchase_orders.approve_high_value', 'purchase_orders.reject', 'purchase_orders.close', 'purchase_orders.cancel', 'purchase_orders.view_history', 'purchase_bills.approve_po_exception', 'approval_delegations.manage', 'approval_authority.assign', 'approval_settings.manage'],
            SystemRole::Management->value => ['account_mappings.view', 'purchase_orders.view_history'],
            SystemRole::Auditor->value => ['account_mappings.view', 'purchase_orders.view_history'],
        ];
        foreach ($milestoneSix as $roleName => $permissions) {
            $roles[$roleName]['permissions'] = array_merge($roles[$roleName]['permissions'], $permissions);
        }

        // Milestone 7: fixed assets, inventory, budgets and payroll.
        $readOnlySeven = ['asset_categories.view', 'fixed_assets.view', 'depreciation.view', 'inventory.view', 'budgets.view', 'budget_reports.view', 'employees.view', 'payroll.view_sensitive'];
        $milestoneSeven = [
            SystemRole::Accountant->value => [...$readOnlySeven, 'asset_categories.manage', 'fixed_assets.create', 'fixed_assets.update', 'fixed_assets.retire', 'depreciation.prepare', 'depreciation.post', 'depreciation.reverse', 'inventory_items.manage', 'inventory.receive', 'inventory.issue', 'inventory.return', 'inventory.adjust', 'inventory.transfer', 'budgets.create', 'budgets.update', 'budgets.submit', 'budgets.revise', 'payroll.import', 'payroll.submit', 'payroll.post', 'payroll.pay', 'payroll_components.manage', 'employees.manage'],
            SystemRole::Approver->value => [...$readOnlySeven, 'depreciation.approve', 'inventory.approve_adjustment', 'budgets.approve', 'payroll.view', 'payroll.approve'],
            SystemRole::Director->value => [...$readOnlySeven, 'depreciation.approve', 'inventory.approve_adjustment', 'budgets.approve', 'payroll.view', 'payroll.approve'],
            SystemRole::Management->value => [...$readOnlySeven, 'payroll.view'],
            SystemRole::Auditor->value => [...$readOnlySeven, 'payroll.view'],
        ];
        foreach ($milestoneSeven as $roleName => $permissions) {
            $roles[$roleName]['permissions'] = array_merge($roles[$roleName]['permissions'], $permissions);
        }

        // Milestone 8: financial statements, exports and the audit viewer.
        $statementReaders = ['financial_statements.view'];
        $exporters = ['financial_statements.export', 'asset_reports.export', 'inventory_reports.export', 'budget_reports.export'];
        $milestoneEight = [
            SystemRole::Accountant->value => [...$statementReaders, ...$exporters, 'cash_flow.manage_classification', 'payroll_reports.export'],
            SystemRole::Approver->value => $statementReaders,
            SystemRole::Director->value => [...$statementReaders, ...$exporters, 'payroll_reports.export'],
            SystemRole::Management->value => [...$statementReaders, ...$exporters],
            SystemRole::Auditor->value => [...$statementReaders, ...$exporters, 'payroll_reports.export'],
        ];
        foreach ($milestoneEight as $roleName => $permissions) {
            $roles[$roleName]['permissions'] = array_merge($roles[$roleName]['permissions'], $permissions);
        }

        // FRC fee integration.
        $frcReaders = ['frc_integration.view'];
        foreach ([SystemRole::Approver->value, SystemRole::Director->value, SystemRole::Management->value, SystemRole::Auditor->value] as $roleName) {
            $roles[$roleName]['permissions'] = array_merge($roles[$roleName]['permissions'], $frcReaders);
        }
        $roles[SystemRole::Accountant->value]['permissions'] = array_merge($roles[SystemRole::Accountant->value]['permissions'], ['frc_integration.view', 'frc_integration.sync', 'frc_integration.post', 'frc_integration.reverse', 'fee_settlement.manage']);

        // Bank reconciliation, petty cash imprest, funds and grants, staff advances.
        foreach (self::RELEASE_GRANTS as $roleName => $names) {
            $roles[$roleName]['permissions'] = array_merge($roles[$roleName]['permissions'], $names);
        }

        foreach ($roles as $name => $definition) {
            $role = Role::query()->updateOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['is_protected' => $definition['protected'] ?? false, 'max_branches' => $definition['max_branches'] ?? null],
            );
            $role->givePermissionTo($definition['permissions']);
        }

        // Director approval authority is a role property, not a permission, so the
        // Administrator role does not gain it by holding every permission.
        Role::query()->where('name', SystemRole::Director->value)->where('guard_name', 'web')->firstOrFail()
            ->forceFill(['approval_authority' => ApprovalAuthorityService::DIRECTOR])->save();
        User::query()->where('is_administrator', true)->each(function (User $user): void {
            if (! $user->hasRole(SystemRole::Administrator->value)) {
                $user->assignRole(SystemRole::Administrator->value);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
