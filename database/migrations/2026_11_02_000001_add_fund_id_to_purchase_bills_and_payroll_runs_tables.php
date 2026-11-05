<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_bills', function (Blueprint $table): void {
            $table->foreignId('fund_id')->nullable()->after('payable_account_id')->constrained('funds')->restrictOnDelete();
        });

        Schema::table('purchase_bill_lines', function (Blueprint $table): void {
            $table->foreignId('fund_id')->nullable()->after('service_id')->constrained('funds')->restrictOnDelete();
        });

        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->foreignId('fund_id')->nullable()->after('accounting_period_id')->constrained('funds')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fund_id');
        });

        Schema::table('purchase_bill_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fund_id');
        });

        Schema::table('purchase_bills', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fund_id');
        });
    }
};
