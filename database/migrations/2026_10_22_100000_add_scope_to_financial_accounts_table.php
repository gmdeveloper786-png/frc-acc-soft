<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A financial account is either used by its own branch ("branch") or shared
     * by every branch ("organization", e.g. the one MCB bank account). A shared
     * account keeps branch_id as its custodian branch; each transaction keeps its
     * own branch. Existing accounts stay "branch"; nothing else changes.
     */
    public function up(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->string('scope', 20)->default('branch')->after('branch_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->dropIndex(['scope']);
            $table->dropColumn('scope');
        });
    }
};
