<?php

namespace App\Services\Shorts;

use RuntimeException;

class YouTubeApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $reason = null,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message, $status);
    }

    public function isQuota(): bool
    {
        return in_array($this->reason, ['quotaExceeded', 'dailyLimitExceeded', 'uploadLimitExceeded', 'rateLimitExceeded'], true);
    }

    public function isAuth(): bool
    {
        return $this->status === 401 || $this->reason === 'invalid_grant' || $this->reason === 'authError';
    }
}
