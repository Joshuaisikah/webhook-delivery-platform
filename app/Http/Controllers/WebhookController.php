<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWebhookRequest;
use App\Models\Application;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class WebhookController extends Controller
{
    public function store(StoreWebhookRequest $request, Application $application): JsonResponse
    {
        try {
            $webhook = $application->webhooks()->create($request->validated());
        } catch (UniqueConstraintViolationException) {
            // The application_id foreign key raises 23000 too, so catch the
            // specific exception.
            throw ValidationException::withMessages([
                'url' => 'The url has already been taken.',
            ]);
        }

        return response()->json(['data' => $webhook], 201);
    }
}
