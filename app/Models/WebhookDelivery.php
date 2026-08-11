<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    use HasFactory;

    /** Status, attempts and the lock columns are written by the worker, not mass assigned. */
    protected $fillable = [
        'application_id',
        'webhook_id',
        'event_id',
    ];

    /** Claim bookkeeping, never part of an API response. */
    protected $hidden = ['locked_by', 'locked_at'];

    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'last_response_code' => 'integer',
            'next_attempt_at' => 'datetime',
            'locked_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /** Columns that return a delivery to the pool. $clearOutcome also wipes the result. */
    public static function returnedToPool(bool $clearOutcome = false): array
    {
        $now = now();

        $attributes = [
            'status' => DeliveryStatus::Pending->value,
            'locked_by' => null,
            'locked_at' => null,
            'next_attempt_at' => $now,
            'updated_at' => $now,
        ];

        if ($clearOutcome) {
            $attributes += [
                'attempts' => 0,
                'last_error' => null,
                'last_response_code' => null,
                'delivered_at' => null,
            ];
        }

        return $attributes;
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
