<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryStatus;
use App\Http\Requests\IndexWebhookDeliveryRequest;
use App\Http\Resources\WebhookDeliveryResource;
use App\Models\Application;
use App\Models\WebhookDelivery;
use Illuminate\Http\JsonResponse;

class WebhookDeliveryController extends Controller
{
    public function index(IndexWebhookDeliveryRequest $request, Application $application): JsonResponse
    {
        $query = $application->deliveries()->with(['webhook:id,url', 'event:id,name']);

        foreach (['status', 'webhook_id', 'event_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        $deliveries = $query->orderByDesc('id')->paginate($request->integer('per_page', 25));

        // Shaped by hand: the paginator would hoist its own keys alongside `data`.
        return response()->json([
            'data' => WebhookDeliveryResource::collection($deliveries->items()),
            'meta' => [
                'current_page' => $deliveries->currentPage(),
                'per_page' => $deliveries->perPage(),
                'last_page' => $deliveries->lastPage(),
                'total' => $deliveries->total(),
            ],
        ]);
    }

    /** Re-queues a failed delivery. The WHERE clause is what makes it atomic. */
    public function retry(Application $application, WebhookDelivery $delivery): JsonResponse
    {
        $updated = WebhookDelivery::query()
            ->whereKey($delivery->getKey())
            ->where('status', DeliveryStatus::Failed->value)
            ->update(WebhookDelivery::returnedToPool(clearOutcome: true));

        if ($updated === 0) {
            return response()->json([
                'message' => 'Only a failed delivery can be retried.',
                'data' => ['status' => $delivery->refresh()->status],
            ], 409);
        }

        return response()->json([
            'message' => 'Delivery re-queued.',
            'data' => new WebhookDeliveryResource($delivery->refresh()),
        ]);
    }
}
