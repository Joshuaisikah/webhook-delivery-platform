<?php

namespace App\Console\Commands;

use App\Enums\DeliveryStatus;
use App\Jobs\ProcessWebhookDeliveryJob;
use App\Models\WebhookDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Claims due deliveries and queues them for sending. Safe to run concurrently.
 */
class ProcessWebhookDeliveries extends Command
{
    protected $signature = 'webhooks:process-deliveries
                            {--limit= : Deliveries to claim per batch}
                            {--once : Claim a single batch instead of draining}';

    protected $description = 'Claim pending webhook deliveries and queue them for background delivery';

    public function handle(): int
    {
        $limit = (int) ($this->option('limit') ?: config('webhooks.claim.batch_size'));

        if ($limit < 1) {
            $this->error('--limit must be a positive integer.');

            return self::FAILURE;
        }

        // Claimed ids are read into memory per batch, so the batch needs a ceiling.
        $maxLimit = (int) config('webhooks.claim.max_batch_size');

        if ($limit > $maxLimit) {
            $this->warn("--limit capped at {$maxLimit} (see config/webhooks.php).");
            $limit = $maxLimit;
        }

        if (($reclaimed = $this->reclaimStaleLocks()) > 0) {
            $this->warn("Reclaimed {$reclaimed} stale delivery lock(s).");
        }

        $dispatched = $this->option('once')
            ? $this->claimAndDispatch($limit)
            : $this->drain($limit);

        if ($dispatched === 0) {
            $this->info('No deliveries ready to process.');

            return self::SUCCESS;
        }

        $this->info("Claimed and queued {$dispatched} delivery(ies).");

        return self::SUCCESS;
    }

    private function drain(int $limit): int
    {
        $deadline = now()->addSeconds((int) config('webhooks.claim.max_run_seconds'));
        $dispatched = 0;

        do {
            if ($this->queueIsSaturated()) {
                break;
            }

            $batch = $this->claimAndDispatch($limit);
            $dispatched += $batch;
        } while ($batch > 0 && now()->lessThan($deadline));

        return $dispatched;
    }

    private function claimAndDispatch(int $limit): int
    {
        $token = (string) Str::uuid();

        if ($this->claim($token, $limit) === 0) {
            return 0;
        }

        return $this->dispatchClaimed($token);
    }

    private function queueIsSaturated(): bool
    {
        if (config('queue.default') !== 'database') {
            return false;
        }

        return DB::table('jobs')
            ->where('queue', config('webhooks.queues.delivery'))
            ->count() >= (int) config('webhooks.claim.max_queue_depth');
    }

    private function reclaimStaleLocks(): int
    {
        $staleBefore = now()->subMinutes(config('webhooks.claim.stale_lock_minutes'));

        return DB::table('webhook_deliveries')
            ->where('status', DeliveryStatus::Processing->value)
            ->where('locked_at', '<=', $staleBefore)
            ->update(WebhookDelivery::returnedToPool());
    }

    /** SKIP LOCKED prevents double claims but not deadlocks between runs. */
    private function claim(string $token, int $limit): int
    {
        return DB::transaction(fn () => $this->claimStatement($token, $limit), attempts: 3);
    }

    /** ORDER BY must match webhook_deliveries_claim_index. The inner LIMIT is required. */
    private function claimStatement(string $token, int $limit): int
    {
        $now = now();

        return DB::update(
            <<<'SQL'
                UPDATE webhook_deliveries
                SET status = ?, locked_by = ?, locked_at = ?, updated_at = ?
                WHERE id IN (
                    SELECT id FROM (
                        SELECT id
                        FROM webhook_deliveries
                        WHERE status = ?
                          AND next_attempt_at <= ?
                        ORDER BY next_attempt_at, id
                        LIMIT ?
                        FOR UPDATE SKIP LOCKED
                    ) AS claimable
                )
                SQL,
            [
                DeliveryStatus::Processing->value,
                $token,
                $now,
                $now,
                DeliveryStatus::Pending->value,
                $now,
                $limit,
            ]
        );
    }

    private function dispatchClaimed(string $token): int
    {
        $ids = DB::table('webhook_deliveries')
            ->where('locked_by', $token)
            ->orderBy('id')
            ->pluck('id');

        $dispatched = 0;

        foreach ($ids as $id) {
            try {
                ProcessWebhookDeliveryJob::dispatch((int) $id, $token)
                    ->onQueue(config('webhooks.queues.delivery'));

                $dispatched++;
            } catch (Throwable $e) {
                Log::error('Failed to queue webhook delivery.', [
                    'delivery_id' => $id,
                    'exception' => $e,
                ]);

                $this->releaseClaim((int) $id, $token);
            }
        }

        return $dispatched;
    }

    /** Nothing was sent, so no attempt is consumed. */
    private function releaseClaim(int $id, string $token): void
    {
        DB::table('webhook_deliveries')
            ->where('id', $id)
            ->where('locked_by', $token)
            ->update(WebhookDelivery::returnedToPool());
    }
}
