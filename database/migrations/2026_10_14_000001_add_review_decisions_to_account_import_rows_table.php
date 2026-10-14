<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_import_rows', function (Blueprint $table): void {
            $table->string('classification', 30)->nullable()->after('review_reasons')->index();
            $table->boolean('requires_manual_review')->default(false)->after('classification')->index();
            $table->string('proposed_decision', 20)->nullable()->after('requires_manual_review');
            $table->text('proposed_reason')->nullable()->after('proposed_decision');
            $table->string('review_status', 20)->default('pending')->after('proposed_reason')->index();
            $table->text('review_notes')->nullable()->after('review_status');
            $table->foreignId('reviewed_by')->nullable()->after('review_notes')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('account_import_rows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['classification', 'requires_manual_review', 'proposed_decision', 'proposed_reason', 'review_status', 'review_notes', 'reviewed_at']);
        });
    }
};
