<?php

namespace App\Services\Frc;

class FrcApiResult
{
    public const string ApiDisabled = 'api_disabled';

    public const string TokenMissing = 'token_missing';

    public const string ServerUnreachable = 'server_unreachable';

    public const string Unauthorized = 'unauthorized';

    public const string Forbidden = 'forbidden';

    public const string NotFound = 'not_found';

    public const string RateLimited = 'rate_limited';

    public const string Connected = 'connected';

    public const string InvalidResponse = 'invalid_response';

    public const string SyncSuccessful = 'sync_successful';

    public const string SyncFailed = 'sync_failed';

    public function __construct(
        public readonly bool $successful,
        public readonly ?int $status,
        public readonly mixed $data,
        public readonly string $message,
        public readonly string $state = self::SyncFailed,
    ) {}

    public function unauthorized(): bool
    {
        return in_array($this->status, [401, 403], true);
    }
}
