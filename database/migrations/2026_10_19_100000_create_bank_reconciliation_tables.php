<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            // 'pending' until the columns are mapped and the lines imported.
            $table->string('status', 20)->default('pending')->index();
            $table->string('original_filename', 200);
            $table->string('stored_path');
            $table->string('file_extension', 10);
            $table->string('file_hash', 64);
            $table->unsignedInteger('header_row')->nullable();
            $table->json('detected_headers')->nullable();
            $table->json('column_mapping')->nullable();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->decimal('opening_balance', 19, 4)->nullable();
            $table->decimal('closing_balance', 19, 4)->nullable();
            $table->unsignedInteger('line_count')->default(0);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
            // The same file can be imported once per bank account.
            $table->unique(['financial_account_id', 'file_hash']);
            $table->index(['financial_account_id', 'status', 'period_to']);
        });

        Schema::create('bank_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_statement_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('statement_date');
            $table->decimal('statement_closing_balance', 19, 4);
            $table->string('status', 20)->default('draft')->index();
            // '1' while the reconciliation is draft or submitted; null once approved, so an account has one open reconciliation.
            $table->string('open_key', 1)->nullable();
            // Figures and outstanding items frozen at approval.
            $table->json('summary')->nullable();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->unique(['financial_account_id', 'open_key']);
            $table->index(['financial_account_id', 'status', 'statement_date'], 'bank_recon_account_status_date_index');
        });

        Schema::create('bank_statement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_statement_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('row_number');
            $table->date('transaction_date');
            $table->string('description', 500)->nullable();
            $table->string('reference', 191)->nullable();
            // Signed: deposits positive, withdrawals negative.
            $table->decimal('amount', 19, 4);
            $table->decimal('running_balance', 19, 4)->nullable();
            $table->string('status', 20)->default('unmatched');
            $table->foreignId('ignored_in_reconciliation_id')->nullable()->constrained('bank_reconciliations')->nullOnDelete();
            $table->timestamps();
            $table->index(['financial_account_id', 'status', 'transaction_date'], 'bank_stmt_lines_account_status_date_index');
        });

        Schema::create('bank_reconciliation_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_reconciliation_id')->constrained()->restrictOnDelete();
            // Null only for a book line cleared as brought forward from before the first imported statement.
            $table->foreignId('bank_statement_line_id')->nullable()->unique()->constrained()->restrictOnDelete();
            // A journal line is matched once, across every reconciliation.
            $table->foreignId('journal_line_id')->unique()->constrained()->restrictOnDelete();
            $table->string('match_type', 20);
            $table->string('note', 500)->nullable();
            $table->foreignId('matched_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_matches');
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('bank_statements');
    }
};
