<?php

namespace App\Services\FeePosting;

use App\Models\AccountingPeriod;

/**
 * The outcome of one eligibility check: either the exact journal that would be
 * posted, or the reasons it cannot be. Building a plan never writes anything.
 */
final class FeePostingPlan
{
    /**
     * @param  array<string, string>  $reasons  reason code => message
     * @param  list<array{account_id: int, account_code: string, account_name: string, service_id: ?int, description: string, debit: string, credit: string}>  $lines
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $basis,
        public readonly array $reasons,
        public readonly array $lines = [],
        public readonly ?string $voucherCode = null,
        public readonly ?string $date = null,
        public readonly ?int $branchId = null,
        public readonly ?string $sourceKey = null,
        public readonly ?string $amount = null,
        public readonly ?AccountingPeriod $period = null,
        public readonly ?string $narration = null,
        public readonly ?string $reference = null,
    ) {}

    public function eligible(): bool
    {
        return $this->reasons === [];
    }

    public function has(string $code): bool
    {
        return array_key_exists($code, $this->reasons);
    }

    public function message(): string
    {
        return implode(' ', $this->reasons);
    }
}
