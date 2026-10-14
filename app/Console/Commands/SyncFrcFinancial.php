<?php

namespace App\Console\Commands;

use App\Services\Frc\FinancialSync\FrcFinancialSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Manual FRC enrollment and payment mirror sync. It is not scheduled and
 * refuses to run without --confirm so that nothing syncs by accident.
 */
#[Signature('frc:sync-financial {--full : Re-read everything instead of continuing from the last cursor} {--confirm : Required: confirms that the sync should run}')]
#[Description('Mirror FRC enrollments and payments (read-only; never posts journals)')]
class SyncFrcFinancial extends Command
{
    public function handle(FrcFinancialSyncService $sync): int
    {
        if (! $this->option('confirm')) {
            $this->error('Refusing to sync without --confirm. Run: php artisan frc:sync-financial --confirm [--full]');

            return self::FAILURE;
        }

        $outcome = $sync->sync($this->option('full') ? FrcFinancialSyncService::ModeFull : FrcFinancialSyncService::ModeIncremental);
        foreach ($outcome->runs as $run) {
            $this->line(sprintf('%s run #%d: %s - %d pages, %d received, %d new, %d updated, %d unchanged, %d failed',
                ucfirst($run->entity), $run->id, $run->status, $run->pages, $run->fetched, $run->created, $run->updated, $run->unchanged, $run->failed));
        }
        $outcome->successful ? $this->info($outcome->message) : $this->error($outcome->message);

        return $outcome->successful ? self::SUCCESS : self::FAILURE;
    }
}
