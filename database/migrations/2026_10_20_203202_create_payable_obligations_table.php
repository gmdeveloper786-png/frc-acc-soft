<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payable_obligations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_expense_transaction_id')->unique()->constrained('operational_transactions')->restrictOnDelete();
            $table->foreignId('source_journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('payable_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('original_amount', 19, 4);
            $table->date('obligation_date')->index();
            $table->string('source_reference')->nullable()->index();
            $table->timestamps();
            $table->index(['branch_id', 'payable_account_id', 'obligation_date'], 'pay_obs_branch_ledger_date_idx');
            $table->index(['vendor_id', 'branch_id'], 'pay_obs_vendor_branch_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payable_obligations');
    }
};
