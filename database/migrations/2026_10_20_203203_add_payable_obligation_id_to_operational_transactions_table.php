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
        Schema::table('operational_transactions', function (Blueprint $table): void {
            $table->foreignId('payable_obligation_id')->nullable()->after('purchase_bill_id')->constrained()->restrictOnDelete();
            $table->index(['payable_obligation_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('operational_transactions', function (Blueprint $table): void {
            $table->dropIndex(['payable_obligation_id', 'status']);
            $table->dropConstrainedForeignId('payable_obligation_id');
        });
    }
};
