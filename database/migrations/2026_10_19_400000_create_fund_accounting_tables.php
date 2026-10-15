<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fund and grant accounting. Funds are configurable records; nothing is
     * inserted here. The fund on journal lines, operational transactions and
     * donation categories is optional, so every existing record keeps a null
     * fund and behaves exactly as before.
     */
    public function up(): void
    {
        Schema::create('funds', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('type', 20)->index();
            $table->string('category', 20)->index();
            $table->foreignId('parent_id')->nullable()->constrained('funds')->restrictOnDelete();
            $table->string('donor_name')->nullable();
            $table->decimal('grant_amount', 19, 4)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('restriction_notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('fund_permitted_accounts', function (Blueprint $table): void {
            $table->foreignId('fund_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->primary(['fund_id', 'account_id']);
        });

        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->foreignId('fund_id')->nullable()->after('service_id')->constrained('funds')->restrictOnDelete();
            $table->index(['fund_id', 'journal_entry_id']);
        });

        Schema::table('operational_transactions', function (Blueprint $table): void {
            $table->foreignId('fund_id')->nullable()->after('service_id')->constrained('funds')->restrictOnDelete();
        });

        Schema::table('donation_categories', function (Blueprint $table): void {
            $table->foreignId('fund_id')->nullable()->after('income_account_id')->constrained('funds')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('donation_categories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fund_id');
        });
        Schema::table('operational_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fund_id');
        });
        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->dropIndex(['fund_id', 'journal_entry_id']);
            $table->dropConstrainedForeignId('fund_id');
        });
        Schema::dropIfExists('fund_permitted_accounts');
        Schema::dropIfExists('funds');
    }
};
