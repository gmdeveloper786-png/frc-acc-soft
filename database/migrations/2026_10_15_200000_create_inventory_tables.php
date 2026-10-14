<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quantity-only inventory. No table here stores a price, cost or value, and
 * nothing in this module posts to the General Ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_items', function (Blueprint $table): void {
            $table->id();
            $table->string('item_code', 40)->unique();
            $table->string('name', 150)->index();
            $table->foreignId('category_id')->constrained('inventory_categories')->restrictOnDelete();
            $table->string('unit_of_measure', 30);
            $table->decimal('minimum_stock_level', 15, 4)->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('inventory_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'name']);
        });

        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->string('type', 20)->index();
            $table->decimal('quantity', 15, 4);
            $table->date('transaction_date')->index();
            $table->string('issued_to', 150)->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_line_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('transfer_group')->nullable()->index();
            $table->string('status', 20)->default('posted');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['item_id', 'branch_id', 'location_id', 'status'], 'inventory_movements_stock_index');
            $table->index(['branch_id', 'status', 'transaction_date'], 'inventory_movements_branch_index');
        });

        Schema::create('inventory_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->timestamps();
            $table->unique(['item_id', 'branch_id', 'location_id'], 'inventory_balances_stock_unique');
            $table->index(['branch_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_balances');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_locations');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('inventory_categories');
    }
};
