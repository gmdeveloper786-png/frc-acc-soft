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
        if (! Schema::hasTable('opening_balance_import_rows')) {
            Schema::create('opening_balance_import_rows', function (Blueprint $table) {
                $table->id();
                $table->foreignId('opening_balance_import_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('row_number');
                $table->string('account_code', 50)->nullable();
                $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
                $table->decimal('debit', 20, 2)->default(0);
                $table->decimal('credit', 20, 2)->default(0);
                $table->string('source_reference')->nullable();
                $table->json('validation_errors')->nullable();
                $table->timestamps();
                $table->unique(['opening_balance_import_id', 'row_number'], 'ob_import_rows_import_row_unique');
            });

            return;
        }

        Schema::table('opening_balance_import_rows', function (Blueprint $table) {
            $table->unique(['opening_balance_import_id', 'row_number'], 'ob_import_rows_import_row_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opening_balance_import_rows');
    }
};
