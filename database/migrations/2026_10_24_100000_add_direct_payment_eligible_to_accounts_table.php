<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expense ledgers an authorized user has approved for direct bank payment
     * (Create Payment > Direct expense). Default deny: payroll, depreciation,
     * write-off and other non-cash ledgers stay out until explicitly approved.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->boolean('direct_payment_eligible')->default(false)->after('purchase_bill_debit_eligible');
            $table->index('direct_payment_eligible', 'accounts_direct_payment_eligible_idx');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropIndex('accounts_direct_payment_eligible_idx');
            $table->dropColumn('direct_payment_eligible');
        });
    }
};
