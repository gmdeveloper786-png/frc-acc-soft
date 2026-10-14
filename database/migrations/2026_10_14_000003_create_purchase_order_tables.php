<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('po_number', 60)->unique();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('po_date')->index();
            $table->date('expected_delivery_date')->nullable();
            $table->string('currency', 3);
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->string('approval_tier', 20)->nullable();
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('discount_total', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('total_amount', 19, 4)->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('closure_reason')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status', 'po_date']);
            $table->index(['vendor_id', 'status']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->string('description', 500);
            $table->decimal('quantity', 15, 4);
            $table->string('unit', 30)->nullable();
            $table->decimal('unit_price', 19, 4);
            $table->decimal('discount_amount', 19, 4)->default(0);
            $table->decimal('tax_amount', 19, 4)->default(0);
            $table->decimal('line_total', 19, 4);
            $table->foreignId('account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('frc_services')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['purchase_order_id', 'line_number']);
        });

        Schema::create('purchase_order_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->string('action', 30)->index();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('approval_tier', 20)->nullable();
            $table->decimal('amount', 19, 4);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('comments')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['purchase_order_id', 'created_at']);
        });

        Schema::table('purchase_bills', function (Blueprint $table): void {
            $table->foreignId('purchase_order_id')->nullable()->after('purchase_order_reference')->constrained()->restrictOnDelete();
            $table->string('po_exception_status', 20)->nullable()->after('purchase_order_id');
            $table->text('po_exception_reason')->nullable()->after('po_exception_status');
            $table->foreignId('po_exception_approved_by')->nullable()->after('po_exception_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('po_exception_approved_at')->nullable()->after('po_exception_approved_by');
        });

        Schema::table('purchase_bill_lines', function (Blueprint $table): void {
            $table->foreignId('purchase_order_line_id')->nullable()->after('service_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_bill_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('purchase_order_line_id');
        });
        Schema::table('purchase_bills', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('po_exception_approved_by');
            $table->dropConstrainedForeignId('purchase_order_id');
            $table->dropColumn(['po_exception_status', 'po_exception_reason', 'po_exception_approved_at']);
        });
        Schema::dropIfExists('purchase_order_approvals');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
