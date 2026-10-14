<?php

namespace App\Services\Frc\FinancialSync;

use Illuminate\Support\Carbon;

/** One cursor page from the FRC financial endpoints, or a sanitised failure. */
final class FrcPage
{
    /** @param list<mixed> $records */
    public function __construct(
        public readonly bool $successful,
        public readonly array $records = [],
        public readonly bool $hasMore = false,
        public readonly ?Carbon $nextUpdatedSince = null,
        public readonly ?int $nextAfterId = null,
        public readonly ?string $error = null,
        public readonly ?string $state = null,
        public readonly ?int $httpStatus = null,
    ) {}

    public static function failed(string $error, ?string $state, ?int $httpStatus): self
    {
        return new self(false, error: $error, state: $state, httpStatus: $httpStatus);
    }
}
