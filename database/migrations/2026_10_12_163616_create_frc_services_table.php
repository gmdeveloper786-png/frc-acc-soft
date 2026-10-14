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
        Schema::create('frc_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('frc_service_id')->unique();
            $table->string('name');
            $table->string('status')->nullable()->index();
            $table->unsignedBigInteger('revenue_account_id')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('frc_services');
    }
};
