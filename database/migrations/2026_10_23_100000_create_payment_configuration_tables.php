<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configurable payee types, a directory for payees that are neither vendors
     * nor employees, and configurable payment purposes. A payee type only picks
     * one of the system sources (vendor, employee, directory) and a purpose only
     * picks one of the protected PaymentBehaviour values, so configuration never
     * creates new integrations or posting rules. New unified payments keep their
     * purpose and payee as plain foreign keys plus a snapshot of the names used.
     * Everything is additive; existing transactions are untouched.
     */
    public function up(): void
    {
        Schema::create('payee_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 50)->unique();
            $table->string('source_kind', 20)->index(); // vendor | employee | directory
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payee_type_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('entity_kind', 20)->default('organization'); // individual | organization
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['payee_type_id', 'is_active', 'name']);
        });

        Schema::create('payment_purposes', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('code', 50)->unique();
            $table->text('description')->nullable();
            $table->string('behaviour', 30)->index(); // App\Enums\PaymentBehaviour
            $table->foreignId('debit_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('operational_transactions', function (Blueprint $table): void {
            $table->foreignId('payment_purpose_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payee_type_id')->nullable()->constrained()->restrictOnDelete();
            // One explicit reference per source; a vendor payee uses the existing vendor_id.
            $table->foreignId('payee_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->foreignId('payee_id')->nullable()->constrained('payees')->restrictOnDelete();
            $table->json('payee_snapshot')->nullable();
        });

        // Starting configuration; admins may rename, reorder, deactivate or add to it.
        $now = now();
        DB::table('payee_types')->insert([
            ['name' => 'Vendor', 'code' => 'vendor', 'source_kind' => 'vendor', 'description' => 'Suppliers from the Vendors master.', 'is_active' => true, 'sort_order' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Employee', 'code' => 'employee', 'source_kind' => 'employee', 'description' => 'Staff from the Employees master.', 'is_active' => true, 'sort_order' => 20, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Other payee', 'code' => 'other_payee', 'source_kind' => 'directory', 'description' => 'Payees kept in the Payee Directory.', 'is_active' => true, 'sort_order' => 30, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('payment_purposes')->insert([
            ['name' => 'Vendor bill payment', 'code' => 'vendor_bill', 'behaviour' => 'vendor_bill', 'is_active' => true, 'sort_order' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Accrued payable settlement', 'code' => 'accrued_payable', 'behaviour' => 'accrued_payable', 'is_active' => true, 'sort_order' => 20, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Salary payment (payroll run)', 'code' => 'salary_payable', 'behaviour' => 'salary_payable', 'is_active' => true, 'sort_order' => 30, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Staff advance / loan disbursement', 'code' => 'staff_advance', 'behaviour' => 'staff_advance', 'is_active' => true, 'sort_order' => 40, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Direct expense', 'code' => 'direct_expense', 'behaviour' => 'direct_expense', 'is_active' => true, 'sort_order' => 50, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::table('operational_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_purpose_id');
            $table->dropConstrainedForeignId('payee_type_id');
            $table->dropConstrainedForeignId('payee_employee_id');
            $table->dropConstrainedForeignId('payee_id');
            $table->dropColumn('payee_snapshot');
        });
        Schema::dropIfExists('payment_purposes');
        Schema::dropIfExists('payees');
        Schema::dropIfExists('payee_types');
    }
};
