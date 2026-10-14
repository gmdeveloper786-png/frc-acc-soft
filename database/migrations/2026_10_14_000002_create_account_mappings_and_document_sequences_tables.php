<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('mapping_key', 60)->unique();
            $table->foreignId('account_id')->unique()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('document_type', 40);
            $table->string('scope_key', 60);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['document_type', 'scope_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('account_mappings');
    }
};
