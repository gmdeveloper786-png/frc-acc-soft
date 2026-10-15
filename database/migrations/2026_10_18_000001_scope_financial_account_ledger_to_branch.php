<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One organisational cash or bank ledger may back one cash/bank account in
     * each branch. Branch balances come from journal_entries.branch_id, so a
     * ledger may appear once per branch, never twice in the same branch.
     */
    public function up(): void
    {
        // The composite index leads with account_id, so it also serves the foreign key
        // once the single-column unique index is dropped.
        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->unique(['account_id', 'branch_id'], 'financial_accounts_account_branch_unique');
        });
        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->dropUnique('financial_accounts_account_id_unique');
        });
    }

    public function down(): void
    {
        $shared = DB::table('financial_accounts')->select('account_id')->groupBy('account_id')->havingRaw('COUNT(*) > 1')->exists();
        if ($shared) {
            throw new RuntimeException('Cannot restore one cash/bank account per ledger: a ledger is already shared by several branches.');
        }

        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->unique('account_id', 'financial_accounts_account_id_unique');
        });
        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->dropUnique('financial_accounts_account_branch_unique');
        });
    }
};
