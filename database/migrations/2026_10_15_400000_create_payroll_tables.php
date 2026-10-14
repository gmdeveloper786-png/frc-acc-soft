<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_code', 40)->unique();
            $table->string('name');
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('designation')->nullable();
            $table->string('employment_status', 20)->default('active')->index();
            $table->date('joined_on')->nullable();
            // Encrypted at rest through the model cast.
            $table->text('bank_details')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'employment_status']);
        });

        Schema::create('payroll_components', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('type', 20)->index();
            $table->string('category', 30)->index();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('employee_salary_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_component_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->timestamps();
            $table->unique(['employee_id', 'payroll_component_id']);
        });

        Schema::create('payroll_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_period_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('draft')->index();
            // '1' while the run occupies its branch and period; null once rejected or reversed.
            $table->string('active_key', 1)->nullable();
            $table->string('source', 20)->default('manual');
            $table->decimal('gross_amount', 19, 4)->default(0);
            $table->decimal('total_deductions', 19, 4)->default(0);
            $table->decimal('net_amount', 19, 4)->default(0);
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'accounting_period_id', 'active_key'], 'payroll_runs_branch_period_active_unique');
        });

        Schema::create('payroll_run_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->decimal('gross_amount', 19, 4)->default(0);
            $table->decimal('total_deductions', 19, 4)->default(0);
            $table->decimal('net_amount', 19, 4)->default(0);
            $table->json('validation_errors')->nullable();
            $table->timestamps();
            $table->unique(['payroll_run_id', 'employee_id']);
        });

        Schema::create('payroll_run_line_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_component_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->timestamps();
            $table->unique(['payroll_run_line_id', 'payroll_component_id'], 'payroll_run_line_items_line_component_unique');
        });

        Schema::create('payroll_run_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->restrictOnDelete();
            $table->string('action', 30)->index();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->json('totals')->nullable();
            // Null when the system reconciles the paid status from posted payments.
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('comments')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['payroll_run_id', 'created_at']);
        });

        Schema::create('payroll_run_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('operational_transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('payroll_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_period_id')->constrained()->restrictOnDelete();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('file_hash', 64)->index();
            $table->string('sheet_name')->nullable();
            $table->unsignedInteger('header_row')->nullable();
            $table->unsignedInteger('data_start_row')->nullable();
            $table->json('detected_headers')->nullable();
            $table->json('column_mapping')->nullable();
            $table->string('status', 20)->default('uploaded')->index();
            $table->json('summary')->nullable();
            $table->foreignId('payroll_run_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'accounting_period_id']);
        });

        Schema::create('payroll_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            // Encrypted at rest through the model cast: holds the mapped salary amounts of the row.
            $table->longText('mapped_values')->nullable();
            $table->json('formula_cells')->nullable();
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();
            $table->boolean('has_formula_warning')->default(false);
            $table->boolean('is_excluded')->default(false);
            $table->timestamps();
            $table->unique(['payroll_import_id', 'row_number']);
        });

        // Generic, account-less starting components. Amounts and ledgers are set by authorised users.
        $now = now();
        $defaults = [
            ['BASIC', 'Basic Salary', 'earning', 'base_salary'],
            ['ALLOWANCE', 'Allowance', 'earning', 'allowance'],
            ['BONUS', 'Bonus', 'earning', 'bonus'],
            ['ARREARS', 'Arrears', 'earning', 'arrears'],
            ['ADVANCE', 'Advance Recovery', 'deduction', 'advance'],
            ['TAX', 'Income Tax', 'deduction', 'tax'],
            ['OTHER_DED', 'Other Deduction', 'deduction', 'other_deduction'],
        ];
        foreach ($defaults as $index => [$code, $name, $type, $category]) {
            DB::table('payroll_components')->insertOrIgnore([
                'code' => $code, 'name' => $name, 'type' => $type, 'category' => $category, 'account_id' => null,
                'is_active' => true, 'sort_order' => ($index + 1) * 10, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_import_rows');
        Schema::dropIfExists('payroll_imports');
        Schema::dropIfExists('payroll_run_payments');
        Schema::dropIfExists('payroll_run_history');
        Schema::dropIfExists('payroll_run_line_items');
        Schema::dropIfExists('payroll_run_lines');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('employee_salary_items');
        Schema::dropIfExists('payroll_components');
        Schema::dropIfExists('employees');
    }
};
