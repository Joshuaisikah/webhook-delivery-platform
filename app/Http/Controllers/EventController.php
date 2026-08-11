<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEventRequest;
use App\Jobs\FanOutEventJob;
use App\Models\Application;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class EventController extends Controller
{
    public function store(StoreEventRequest $request, Application $application): JsonResponse
    {
        $event = DB::transaction(function () use ($request, $application): Event {
            $event = $application->events()->create($request->validated());

            // afterCommit: a worker must not see the job before the row commits.
            FanOutEventJob::dispatch($event)
                ->onQueue(config('webhooks.queues.fanout'))
                ->afterCommit();

            return $event;
        });

        return response()->json(['data' => $event], 201);
    }
}
