<?php

namespace App\Jobs;

use App\Enums\DeliveryStatus;
use App\Models\Event;
use App\Models\Webhook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/** Writes one pending delivery row per active webhook. Sends nothing itself. */
class FanOutEventJob implements ShouldQueue
{
    use Queueable;

    /** Safe to retry: the inserts are INSERT IGNORE against a unique index. */
    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Event $event) {}

    public function handle(): void
    {
        $now = now();

        // INSERT IGNORE downgrades every error to a warning, so a misconfigured
        // value would be coerced silently. Clamped to the tinyint column range too.
        $maxAttempts = max(1, min(255, (int) config('webhooks.max_attempts')));

        Webhook::query()
            ->where('application_id', $this->event->application_id)
            ->active()
            ->select('id')
            // Pages on the primary key so a concurrent insert cannot shift a page.
            ->chunkById(config('webhooks.fanout_chunk_size'), function ($webhooks) use ($now, $maxAttempts) {
                $rows = $webhooks->map(fn (Webhook $webhook) => [
                    'application_id' => $this->event->application_id,
                    'webhook_id' => $webhook->id,
                    'event_id' => $this->event->id,
                    'status' => DeliveryStatus::Pending->value,
                    'attempts' => 0,
                    'max_attempts' => $maxAttempts,
                    'next_attempt_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('webhook_deliveries')->insertOrIgnore($rows);
            });
    }
}
