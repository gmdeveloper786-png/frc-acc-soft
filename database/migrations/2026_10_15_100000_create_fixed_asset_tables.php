<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('default_useful_life_months');
            $table->foreignId('asset_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('accumulated_depreciation_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('depreciation_expense_account_id')->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('fixed_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('asset_code', 40)->unique();
            $table->string('name', 200);
            $table->foreignId('category_id')->constrained('asset_categories')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->date('acquisition_date');
            $table->date('in_service_date')->index();
            $table->decimal('acquisition_cost', 19, 4);
            $table->decimal('residual_value', 19, 4)->default(0);
            $table->unsignedSmallInteger('useful_life_months');
            $table->decimal('opening_accumulated_depreciation', 19, 4)->default(0);
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('location', 150)->nullable();
            $table->string('custodian', 150)->nullable();
            $table->foreignId('vendor_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('purchase_bill_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('asset_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('accumulated_depreciation_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('depreciation_expense_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->text('retirement_reason')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
            $table->index(['category_id', 'status']);
        });

        Schema::create('fixed_asset_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained()->restrictOnDelete();
            $table->string('disk', 30)->default('local');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('fixed_asset_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained()->restrictOnDelete();
            $table->string('event', 40)->index();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('details')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['fixed_asset_id', 'created_at']);
        });

        Schema::create('depreciation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_period_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->string('convention', 30);
            $table->decimal('total_amount', 19, 4)->default(0);
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'accounting_period_id', 'status']);
        });

        Schema::create('depreciation_run_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('depreciation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_period_id')->constrained()->restrictOnDelete();
            $table->decimal('depreciation_amount', 19, 4);
            $table->decimal('accumulated_before', 19, 4);
            $table->decimal('accumulated_after', 19, 4);
            $table->decimal('net_book_value_after', 19, 4);
            // '1' while the line counts; NULL once its run is reversed or rejected,
            // which releases the unique slot for a later run of the same period.
            $table->string('active_key', 1)->nullable();
            $table->timestamps();
            $table->unique(['fixed_asset_id', 'accounting_period_id', 'active_key'], 'depreciation_lines_asset_period_active_unique');
            $table->index(['depreciation_run_id', 'fixed_asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_run_lines');
        Schema::dropIfExists('depreciation_runs');
        Schema::dropIfExists('fixed_asset_events');
        Schema::dropIfExists('fixed_asset_documents');
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('asset_categories');
    }
};
