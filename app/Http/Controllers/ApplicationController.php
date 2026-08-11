<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreApplicationRequest;
use App\Models\Application;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ApplicationController extends Controller
{
    public function store(StoreApplicationRequest $request): JsonResponse
    {
        try {
            $application = Application::create($request->validated());
        } catch (UniqueConstraintViolationException) {
            // Two requests can pass the validator before either insert lands.
            throw ValidationException::withMessages([
                'name' => 'The name has already been taken.',
            ]);
        }

        return response()->json(['data' => $application], 201);
    }
}
