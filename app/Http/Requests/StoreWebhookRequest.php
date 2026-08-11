<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWebhookRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'url' => [
                'required',
                'string',
                'url:http,https',
                'max:500',
                $this->withoutCredentials(),
                Rule::unique('webhooks', 'url')->where(
                    fn ($query) => $query->where('application_id', $this->route('application')->id)
                ),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** Rejects http://user:pass@host URLs; credentials must not be stored. */
    private function withoutCredentials(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $parts = parse_url((string) $value);

            if (isset($parts['user']) || isset($parts['pass'])) {
                $fail('The url must not contain embedded credentials.');
            }
        };
    }
}
