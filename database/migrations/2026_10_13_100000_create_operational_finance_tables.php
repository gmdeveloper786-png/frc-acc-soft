<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 20)->index();
            $table->string('name');
            $table->foreignId('account_id')->unique()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('bank_name')->nullable();
            $table->string('account_title')->nullable();
            $table->text('account_number')->nullable();
            $table->string('account_last_four', 4)->nullable();
            $table->string('currency', 3)->default('PKR');
            $table->boolean('is_active')->default(true)->index();
            $table->string('opening_reference')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'type', 'is_active']);
        });

        Schema::create('vendors', function (Blueprint $table): void {
            $table->id();
            $table->string('vendor_code', 50)->unique();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('tax_registration')->nullable();
            $table->text('payment_details')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('donation_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->foreignId('income_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('purchase_bills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('payable_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('bill_number');
            $table->date('invoice_date')->index();
            $table->date('due_date')->nullable()->index();
            $table->string('purchase_order_reference')->nullable();
            $table->text('description')->nullable();
            $table->decimal('total_amount', 19, 4);
            $table->string('status')->default('draft')->index();
            $table->string('attachment_path')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->unique(['vendor_id', 'branch_id', 'bill_number']);
        });

        Schema::create('purchase_bill_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('frc_services')->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->string('description');
            $table->decimal('quantity', 15, 4)->default(1);
            $table->decimal('unit_amount', 19, 4);
            $table->decimal('line_total', 19, 4);
            $table->timestamps();
            $table->unique(['purchase_bill_id', 'line_number']);
        });

        Schema::create('operational_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 40)->index();
            $table->string('status')->default('draft')->index();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('transaction_date')->index();
            $table->string('party_name')->nullable();
            $table->decimal('amount', 19, 4);
            $table->foreignId('financial_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('contra_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('payable_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('frc_services')->restrictOnDelete();
            $table->foreignId('donation_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('purchase_bill_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reference_number')->nullable()->index();
            $table->text('narration');
            $table->string('settlement_type', 20)->nullable();
            $table->string('approval_tier', 20)->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('source_system', 50)->default('accounting');
            $table->string('source_reference')->nullable();
            $table->string('source_key')->nullable()->unique();
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'type', 'transaction_date']);
            $table->index(['purchase_bill_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_transactions');
        Schema::dropIfExists('purchase_bill_lines');
        Schema::dropIfExists('purchase_bills');
        Schema::dropIfExists('donation_categories');
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('financial_accounts');
    }
};
