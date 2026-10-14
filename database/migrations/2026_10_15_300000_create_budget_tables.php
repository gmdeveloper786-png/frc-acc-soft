<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('frc_services')->restrictOnDelete();
            // Never null ("all" or "service:{id}") so the unique index below is not defeated by NULLs.
            $table->string('service_scope', 40);
            $table->string('name', 150);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('root_budget_id')->nullable()->constrained('budgets')->restrictOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->decimal('total_amount', 19, 4)->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('revision_reason')->nullable();
            // "1" only while the budget is approved; NULL otherwise, so any number of non-approved versions may coexist.
            $table->string('active_key', 1)->nullable();
            $table->timestamps();
            $table->unique(['fiscal_year_id', 'branch_id', 'service_scope', 'active_key'], 'budgets_one_approved_per_scope');
            $table->unique(['root_budget_id', 'version'], 'budgets_chain_version_unique');
            $table->index(['branch_id', 'fiscal_year_id', 'status']);
        });

        Schema::create('budget_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('accounting_period_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->timestamps();
            $table->unique(['budget_id', 'account_id', 'accounting_period_id'], 'budget_lines_account_period_unique');
        });

        Schema::create('budget_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_id')->constrained()->restrictOnDelete();
            $table->string('action', 30)->index();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->decimal('total_amount', 19, 4);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('comments')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['budget_id', 'created_at']);
        });

        Schema::create('budget_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            // NULL for a year-to-date alert; period_scope carries the non-null identity used for uniqueness.
            $table->foreignId('accounting_period_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('period_scope', 40);
            $table->string('level', 20)->index();
            $table->decimal('utilisation_percentage', 15, 2)->nullable();
            $table->decimal('actual_amount', 19, 4);
            $table->decimal('budget_amount', 19, 4);
            $table->unsignedSmallInteger('warning_percentage');
            $table->foreignId('detected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('detected_at');
            $table->unique(['budget_id', 'account_id', 'period_scope', 'level'], 'budget_alerts_once_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_alerts');
        Schema::dropIfExists('budget_approvals');
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
    }
};
