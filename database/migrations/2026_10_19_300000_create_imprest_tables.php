<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imprest_floats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            // The petty cash ledger the float is held in: the petty cash mapping ledger or a cash ledger of the branch.
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('float_amount', 19, 4);
            // Bank or cash account of the same branch that replenishments are drawn from.
            $table->foreignId('source_financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->string('custodian_name', 150);
            $table->boolean('is_active')->default(true);
            // "1" only while the float is active; NULL otherwise, so inactive floats never collide in the unique index.
            $table->string('active_key', 1)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['branch_id', 'account_id', 'active_key'], 'imprest_floats_one_active_per_ledger');
        });

        Schema::create('imprest_replenishments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('imprest_float_id')->constrained()->restrictOnDelete();
            // A discarded draft journal leaves the replenishment cancelled rather than deleting its history.
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->decimal('amount', 19, 4);
            $table->decimal('due_at_request', 19, 4);
            $table->json('covered_voucher_ids');
            $table->date('covered_from')->nullable();
            $table->date('covered_to')->nullable();
            // Mirrors the journal: draft, submitted, approved, rejected, posted, reversed, or cancelled when the draft was discarded.
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['imprest_float_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imprest_replenishments');
        Schema::dropIfExists('imprest_floats');
    }
};
