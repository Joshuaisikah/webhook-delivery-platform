<?php

namespace App\Http\Requests;

use App\Enums\DeliveryStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexWebhookDeliveryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(DeliveryStatus::class)],
            'webhook_id' => ['sometimes', 'integer', 'min:1'],
            'event_id' => ['sometimes', 'integer', 'min:1'],
            // Capped so a caller cannot request an unbounded page.
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
