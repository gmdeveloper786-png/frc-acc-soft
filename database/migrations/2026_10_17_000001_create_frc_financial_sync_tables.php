<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Read-only mirror of FRC enrollments and fee payments, sync history,
     * settlement mappings and fee postings. Mirror rows are source records;
     * journals are created only by the separate, authorized posting step.
     */
    public function up(): void
    {
        Schema::create('frc_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('entity', 30);
            $table->string('mode', 20);
            $table->string('status', 20)->index();
            $table->timestamp('cursor_from')->nullable();
            $table->unsignedBigInteger('cursor_from_id')->nullable();
            $table->timestamp('cursor_to')->nullable();
            $table->unsignedBigInteger('cursor_to_id')->nullable();
            $table->unsignedInteger('pages')->default(0);
            $table->unsignedInteger('fetched')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('unchanged')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->text('error')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['entity', 'status', 'finished_at']);
        });

        Schema::create('frc_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('frc_enrollment_id')->unique();
            $table->unsignedBigInteger('frc_branch_id')->nullable();
            $table->unsignedBigInteger('frc_service_id')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('frc_services')->restrictOnDelete();
            $table->string('source_status', 40)->index();
            $table->string('payment_status', 30)->nullable();
            $table->decimal('final_total', 19, 4)->default(0);
            $table->decimal('discount_amount', 19, 4)->default(0);
            $table->decimal('paid_amount', 19, 4)->default(0);
            $table->decimal('remaining_amount', 19, 4)->default(0);
            $table->timestamp('source_approved_at')->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable()->index();
            $table->timestamp('source_deleted_at')->nullable();
            $table->string('content_hash', 64);
            $table->foreignId('last_sync_run_id')->nullable()->constrained('frc_sync_runs')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('frc_payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('frc_payment_id')->unique();
            $table->string('receipt_number', 40)->nullable()->index();
            $table->unsignedBigInteger('frc_enrollment_id')->index();
            $table->foreignId('frc_enrollment_mirror_id')->nullable()->constrained('frc_enrollments')->nullOnDelete();
            $table->unsignedBigInteger('frc_branch_id')->nullable();
            $table->unsignedBigInteger('frc_service_id')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('frc_services')->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->string('payment_method', 30)->index();
            $table->string('source_status', 30)->index();
            $table->string('enrollment_status', 40)->nullable();
            $table->date('payment_date')->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable()->index();
            $table->timestamp('source_deleted_at')->nullable();
            $table->string('content_hash', 64);
            $table->string('posting_status', 20)->default('unposted')->index();
            $table->string('posting_note')->nullable();
            $table->foreignId('last_sync_run_id')->nullable()->constrained('frc_sync_runs')->nullOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'payment_date']);
        });

        Schema::create('frc_sync_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('frc_sync_run_id')->nullable()->constrained('frc_sync_runs')->nullOnDelete();
            $table->string('entity', 30);
            $table->unsignedBigInteger('external_id')->nullable();
            $table->string('code', 60)->index();
            $table->string('message', 500);
            $table->timestamp('resolved_at')->nullable()->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['entity', 'external_id']);
        });

        Schema::create('fee_settlement_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('payment_method', 30);
            $table->foreignId('financial_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['branch_id', 'payment_method']);
        });

        Schema::create('frc_fee_postings', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 30);
            $table->string('basis', 10);
            $table->foreignId('frc_payment_id')->nullable()->constrained('frc_payments')->restrictOnDelete();
            $table->foreignId('frc_enrollment_id')->nullable()->constrained('frc_enrollments')->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->string('source_key')->unique();
            $table->foreignId('journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->string('status', 20)->default('posted')->index();
            $table->foreignId('posted_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
        });

        $now = now();
        $settings = [
            ['key' => 'fees.accounting_basis', 'value' => 'cash', 'type' => 'string', 'group' => 'fees'],
            ['key' => 'fees.accounting_policy_approved', 'value' => '0', 'type' => 'integer', 'group' => 'fees'],
        ];
        foreach ($settings as $setting) {
            if (! DB::table('app_settings')->where('key', $setting['key'])->exists()) {
                DB::table('app_settings')->insert([...$setting, 'is_public' => false, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        $permissions = ['frc_integration.view', 'frc_integration.sync', 'frc_integration.post', 'frc_integration.reverse', 'fee_settlement.manage'];
        $ids = [];
        foreach ($permissions as $name) {
            $ids[$name] = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id')
                ?: DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => 'web', 'group_name' => 'FRC Integration', 'created_at' => $now, 'updated_at' => $now]);
        }
        $grants = [
            'Administrator' => $permissions,
            'Accountant' => $permissions,
            'Approver / Manager' => ['frc_integration.view'],
            'Director' => ['frc_integration.view'],
            'Management / Board' => ['frc_integration.view'],
            'Auditor' => ['frc_integration.view'],
        ];
        foreach ($grants as $roleName => $names) {
            $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', 'web')->value('id');
            foreach ($roleId ? $names : [] as $name) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $ids[$name], 'role_id' => $roleId]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->where('group_name', 'FRC Integration')->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        DB::table('app_settings')->whereIn('key', ['fees.accounting_basis', 'fees.accounting_policy_approved'])->delete();
        Schema::dropIfExists('frc_fee_postings');
        Schema::dropIfExists('fee_settlement_mappings');
        Schema::dropIfExists('frc_sync_exceptions');
        Schema::dropIfExists('frc_payments');
        Schema::dropIfExists('frc_enrollments');
        Schema::dropIfExists('frc_sync_runs');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
