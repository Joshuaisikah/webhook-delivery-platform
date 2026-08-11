<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The public shape of a delivery. */
class WebhookDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'webhook_id' => $this->webhook_id,
            'event_id' => $this->event_id,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'next_attempt_at' => $this->next_attempt_at,
            'delivered_at' => $this->delivered_at,
            'last_response_code' => $this->last_response_code,
            'last_error' => $this->last_error,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'webhook' => $this->whenLoaded('webhook', fn () => [
                'id' => $this->webhook->id,
                'url' => $this->webhook->url,
            ]),
            'event' => $this->whenLoaded('event', fn () => [
                'id' => $this->event->id,
                'name' => $this->event->name,
            ]),
        ];
    }
}
