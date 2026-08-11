<?php

namespace App\Services;

use App\Models\WebhookDelivery;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class WebhookClient
{
    public function send(WebhookDelivery $delivery): Response
    {
        $event = $delivery->event;

        return Http::timeout(config('webhooks.http.timeout'))
            ->connectTimeout(config('webhooks.http.connect_timeout'))
            // A validated public URL can still 302 to an internal address.
            ->withoutRedirecting()
            ->withHeaders([
                // Stable across resends: delivery is at-least-once.
                'X-Webhook-Delivery-Id' => (string) $delivery->id,
                'X-Webhook-Event' => $event->name,
                'X-Webhook-Event-Id' => (string) $event->id,
                'X-Webhook-Attempt' => (string) ($delivery->attempts + 1),
            ])
            ->post($delivery->webhook->url, [
                'id' => $event->id,
                'event' => $event->name,
                'created_at' => $event->created_at->toIso8601String(),
                'data' => $event->payload,
            ]);
    }
}
