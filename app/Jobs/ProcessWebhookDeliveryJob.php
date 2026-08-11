<?php

namespace App\Jobs;

use App\Enums\DeliveryStatus;
use App\Models\WebhookDelivery;
use App\Services\RetryPolicy;
use App\Services\WebhookClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProcessWebhookDeliveryJob implements ShouldQueue
{
    use Queueable;

    private const ERROR_LENGTH = 1000;

    private const BODY_SNIPPET_LENGTH = 200;

    /** Retry state lives in the delivery row, not in the queue. */
    public int $tries = 1;

    /** $claimToken fences this job to the claim that created it. */
    public function __construct(public int $deliveryId, public string $claimToken) {}

    public function handle(WebhookClient $client, RetryPolicy $policy): void
    {
        $delivery = WebhookDelivery::with(['webhook', 'event'])->find($this->deliveryId);

        if (! $this->stillOurs($delivery)) {
            return;
        }

        if ($reason = $this->undeliverableReason($delivery)) {
            $this->markTerminal($delivery, $delivery->attempts, null, $reason);

            return;
        }

        $attempt = $delivery->attempts + 1;
        $startedAt = microtime(true);

        // Nothing may escape: an uncaught exception strands the row in `processing`.
        try {
            $response = $client->send($delivery);

            $this->recordResponse(
                $delivery,
                $policy,
                $attempt,
                $response,
                (int) round((microtime(true) - $startedAt) * 1000)
            );
        } catch (ConnectionException $e) {
            $this->log('warning', 'Webhook delivery could not reach the endpoint.', $delivery, $attempt, [
                'exception' => $e->getMessage(),
            ]);

            $this->markAttemptFailed($delivery, $policy, $attempt, null, $this->connectionFailureReason($e));
        } catch (Throwable $e) {
            $this->log('error', 'Webhook delivery failed with an internal error.', $delivery, $attempt, [
                'exception' => $e,
            ]);

            $this->markAttemptFailed($delivery, $policy, $attempt, null, 'Internal error while attempting delivery.');
        }
    }

    /** Covers failures handle() cannot see, such as the worker being killed. */
    public function failed(?Throwable $exception): void
    {
        $delivery = WebhookDelivery::find($this->deliveryId);

        if (! $this->stillOurs($delivery)) {
            return;
        }

        Log::error('Webhook delivery job failed outside handle().', [
            'delivery_id' => $this->deliveryId,
            'exception' => $exception,
        ]);

        $this->markAttemptFailed(
            $delivery,
            app(RetryPolicy::class),
            $delivery->attempts + 1,
            null,
            'Delivery job did not complete.'
        );
    }

    private function recordResponse(
        WebhookDelivery $delivery,
        RetryPolicy $policy,
        int $attempt,
        Response $response,
        int $durationMs
    ): void {
        $status = $response->status();

        if ($response->successful()) {
            $this->log('info', 'Webhook delivered.', $delivery, $attempt, [
                'status' => $status,
                'duration_ms' => $durationMs,
            ]);

            $this->finish($delivery, [
                'status' => DeliveryStatus::Delivered->value,
                'attempts' => $attempt,
                'last_response_code' => $status,
                'last_error' => null,
                'delivered_at' => now(),
            ]);

            return;
        }

        $this->log('warning', 'Webhook delivery rejected by endpoint.', $delivery, $attempt, [
            'status' => $status,
            'duration_ms' => $durationMs,
            'body_snippet' => Str::limit($response->body(), self::BODY_SNIPPET_LENGTH),
        ]);

        if ($policy->isPermanentRejection($status)) {
            $reason = "Endpoint returned HTTP {$status}; not retried (permanent).";
            $this->markTerminal($delivery, $attempt, $status, $reason);

            return;
        }

        $this->markAttemptFailed($delivery, $policy, $attempt, $status, "Endpoint returned HTTP {$status}");
    }

    private function undeliverableReason(WebhookDelivery $delivery): ?string
    {
        if ($delivery->webhook === null || ! $delivery->webhook->is_active) {
            return 'Webhook is no longer active.';
        }

        return $delivery->event === null ? 'Event no longer exists.' : null;
    }

    private function stillOurs(?WebhookDelivery $delivery): bool
    {
        return $delivery !== null
            && $delivery->status === DeliveryStatus::Processing
            && $delivery->locked_by === $this->claimToken;
    }

    /** Kept coarse: last_error is readable through the API. */
    private function connectionFailureReason(ConnectionException $e): string
    {
        return str_contains(strtolower($e->getMessage()), 'timed out')
            ? 'Request timed out.'
            : 'Could not connect to the endpoint.';
    }

    private function markTerminal(WebhookDelivery $delivery, int $attempts, ?int $status, string $reason): void
    {
        $this->finish($delivery, [
            'status' => DeliveryStatus::Failed->value,
            'attempts' => $attempts,
            'last_response_code' => $status,
            'last_error' => Str::limit($reason, self::ERROR_LENGTH),
            'delivered_at' => null,
        ]);
    }

    private function markAttemptFailed(
        WebhookDelivery $delivery,
        RetryPolicy $policy,
        int $attempt,
        ?int $status,
        string $error
    ): void {
        $exhausted = $policy->isExhausted($attempt, $delivery->max_attempts);

        $this->finish($delivery, [
            'status' => $exhausted ? DeliveryStatus::Failed->value : DeliveryStatus::Pending->value,
            'attempts' => $attempt,
            'last_response_code' => $status,
            'last_error' => Str::limit($error, self::ERROR_LENGTH),
            'delivered_at' => null,
            'next_attempt_at' => $exhausted
                ? $delivery->next_attempt_at
                : $policy->nextAttemptAt($attempt),
        ]);
    }

    /** Writes the outcome only while this job still holds the claim. */
    private function finish(WebhookDelivery $delivery, array $attributes): void
    {
        $written = WebhookDelivery::query()
            ->whereKey($delivery->getKey())
            ->where('locked_by', $this->claimToken)
            ->update($attributes + [
                'locked_by' => null,
                'locked_at' => null,
                'updated_at' => now(),
            ]);

        if ($written === 0) {
            Log::warning('Discarded a webhook delivery outcome from a superseded claim.', [
                'delivery_id' => $delivery->id,
                'claim_token' => $this->claimToken,
            ]);
        }
    }

    private function log(
        string $level,
        string $message,
        WebhookDelivery $delivery,
        int $attempt,
        array $context = []
    ): void {
        Log::{$level}($message, [
            'delivery_id' => $delivery->id,
            'webhook_id' => $delivery->webhook_id,
            'attempt' => $attempt,
        ] + $context);
    }
}
