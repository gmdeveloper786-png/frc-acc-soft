<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records the branch of an audited record so the audit viewer can filter
     * and scope by branch. Earlier entries keep a null branch.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('auditable_id')->constrained()->nullOnDelete();
            $table->index(['created_at', 'event']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['created_at', 'event']);
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
