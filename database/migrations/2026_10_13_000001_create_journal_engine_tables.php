<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->string('prefix', 20);
            $table->string('year_format', 10)->default('Y');
            $table->unsignedTinyInteger('padding')->default(5);
            $table->string('reset_period')->default('yearly');
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_system')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('voucher_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('voucher_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('scope_key', 100);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['voucher_type_id', 'scope_key']);
        });

        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('voucher_type_id')->constrained()->restrictOnDelete();
            $table->string('voucher_number', 100)->nullable()->unique();
            $table->date('transaction_date')->index();
            $table->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->text('narration');
            $table->string('reference_number')->nullable()->index();
            $table->string('source_system', 50)->nullable();
            $table->string('source_type', 100)->nullable();
            $table->string('source_id', 100)->nullable();
            $table->string('source_key', 255)->nullable()->unique();
            $table->string('status')->default('draft')->index();
            $table->decimal('total_debit', 19, 4)->default(0);
            $table->decimal('total_credit', 19, 4)->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable()->index();
            $table->timestamp('reversed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'transaction_date', 'status']);
            $table->index(['fiscal_year_id', 'accounting_period_id', 'status']);
            $table->index(['source_system', 'source_type', 'source_id']);
        });

        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->string('description')->nullable();
            $table->decimal('debit', 19, 4)->default(0);
            $table->decimal('credit', 19, 4)->default(0);
            $table->foreignId('service_id')->nullable()->constrained('frc_services')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['journal_entry_id', 'line_number']);
            $table->index(['account_id', 'journal_entry_id']);
            $table->index(['service_id', 'journal_entry_id']);
        });

        Schema::table('opening_balance_batches', function (Blueprint $table): void {
            $table->foreignId('approved_by')->nullable()->after('updated_by')->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->unique()->after('posted_by')->constrained('journal_entries')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable()->after('journal_entry_id');
            $table->timestamp('posted_at')->nullable()->after('approved_at');
            $table->text('rejection_reason')->nullable()->after('posted_at');
        });
    }

    public function down(): void
    {
        Schema::table('opening_balance_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('posted_by');
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropColumn(['approved_at', 'posted_at', 'rejection_reason']);
        });
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('voucher_sequences');
        Schema::dropIfExists('voucher_types');
    }
};
