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
        Schema::table('permissions', function (Blueprint $table): void {
            $table->string('group_name')->after('guard_name')->index();
        });
        Schema::table('roles', function (Blueprint $table): void {
            $table->boolean('is_protected')->default(false)->after('guard_name');
            $table->unsignedSmallInteger('max_branches')->nullable()->after('is_protected');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('permissions', fn (Blueprint $table) => $table->dropColumn('group_name'));
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn(['is_protected', 'max_branches']));
    }
};
