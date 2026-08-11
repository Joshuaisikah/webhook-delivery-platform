<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Decides whether a failed delivery is worth another attempt, and when. Separate
 * from the job so the rules are testable without an HTTP round trip.
 */
class RetryPolicy
{
    /**
     * 4xx statuses that mean "try again" despite being client errors.
     */
    private const RETRYABLE_CLIENT_ERRORS = [408, 429];

    /**
     * Whether a status means the request will never be accepted as sent. 3xx counts,
     * because redirects are not followed.
     */
    public function isPermanentRejection(int $status): bool
    {
        if ($status >= 300 && $status < 400) {
            return true;
        }

        if ($status < 400 || $status >= 500) {
            return false;
        }

        return ! in_array($status, self::RETRYABLE_CLIENT_ERRORS, true);
    }

    public function isExhausted(int $attempt, int $maxAttempts): bool
    {
        return $attempt >= $maxAttempts;
    }

    public function nextAttemptAt(int $attempt): Carbon
    {
        $delay = min(
            config('webhooks.backoff.base_seconds') * (2 ** ($attempt - 1)),
            config('webhooks.backoff.max_seconds')
        );

        return now()->addSeconds((int) $delay);
    }
}
