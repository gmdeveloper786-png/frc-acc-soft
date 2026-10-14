<?php

namespace App\Services\Frc\FinancialSync;

use App\Models\FrcSyncRun;

/** Result of one financial sync request (enrollments then payments). */
final class FrcSyncOutcome
{
    /** @param list<FrcSyncRun> $runs */
    public function __construct(
        public readonly bool $successful,
        public readonly array $runs,
        public readonly string $message,
        public readonly bool $alreadyRunning = false,
    ) {}
}
