<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_flow_classifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained('accounts')->restrictOnDelete();
            $table->string('activity', 20)->index();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_flow_classifications');
    }
};
