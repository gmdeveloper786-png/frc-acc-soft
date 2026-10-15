<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff advances and loans with their recovery schedule. A register only:
 * disbursement is a payment of the existing payment workflow linked here, and
 * recovery is read from posted payroll runs. Nothing in these tables posts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_advances', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->nullable()->unique();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->decimal('principal_amount', 19, 4);
            // Exactly one of the two is entered; the schedule records the outcome.
            $table->unsignedSmallInteger('instalment_count')->nullable();
            $table->decimal('instalment_amount', 19, 4)->nullable();
            $table->foreignId('first_recovery_period_id')->constrained('accounting_periods')->restrictOnDelete();
            $table->foreignId('payroll_component_id')->constrained()->restrictOnDelete();
            $table->text('purpose');
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('draft')->index();
            // The posted cash or bank payment of the payment workflow that paid the advance out.
            $table->foreignId('disbursement_transaction_id')->nullable()->unique()->constrained('operational_transactions')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('disbursement_linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'payroll_component_id', 'status']);
            $table->index(['branch_id', 'status']);
        });

        Schema::create('staff_advance_instalments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_advance_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->foreignId('accounting_period_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->decimal('recovered_amount', 19, 4)->default(0);
            // The posted payroll run line whose recovery deduction last settled (part of) this instalment.
            $table->foreignId('payroll_run_line_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['staff_advance_id', 'sequence']);
            $table->unique(['staff_advance_id', 'accounting_period_id'], 'staff_advance_instalments_period_unique');
        });

        Schema::create('staff_advance_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_advance_id')->constrained()->cascadeOnDelete();
            $table->string('action', 30)->index();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            // Null when the system reconciles recovery from posted payroll runs.
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('comments')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['staff_advance_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_advance_history');
        Schema::dropIfExists('staff_advance_instalments');
        Schema::dropIfExists('staff_advances');
    }
};
