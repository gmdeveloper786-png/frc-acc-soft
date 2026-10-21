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
        Schema::table('accounts', function (Blueprint $table): void {
            $table->boolean('purchase_bill_debit_eligible')->default(false)->after('financial_account_type');
            $table->index('purchase_bill_debit_eligible', 'accounts_purchase_bill_debit_eligible_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropIndex('accounts_purchase_bill_debit_eligible_idx');
            $table->dropColumn('purchase_bill_debit_eligible');
        });
    }
};
