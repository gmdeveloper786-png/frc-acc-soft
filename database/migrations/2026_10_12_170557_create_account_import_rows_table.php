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
        Schema::create('account_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('code', 50)->nullable();
            $table->string('name')->nullable();
            $table->string('level')->nullable();
            $table->string('parent_code', 50)->nullable();
            $table->string('account_type')->nullable();
            $table->string('normal_balance')->nullable();
            $table->string('financial_statement')->nullable();
            $table->string('source_entity')->nullable();
            $table->boolean('is_postable')->nullable();
            $table->text('notes')->nullable();
            $table->json('validation_errors')->nullable();
            $table->json('review_reasons')->nullable();
            $table->boolean('is_existing')->default(false)->index();
            $table->boolean('is_selected')->default(false)->index();
            $table->timestamps();
            $table->unique(['account_import_id', 'row_number']);
            $table->index(['account_import_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_import_rows');
    }
};
