<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreEventRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'payload' => ['required', 'array', $this->withinSizeLimit()],
        ];
    }

    /** `max` counts array elements, not bytes, so measure the encoded size. */
    private function withinSizeLimit(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $limit = (int) config('webhooks.max_payload_bytes');

            if (strlen((string) json_encode($value)) > $limit) {
                $fail("The payload may not be larger than {$limit} bytes when encoded as JSON.");
            }
        };
    }
}
