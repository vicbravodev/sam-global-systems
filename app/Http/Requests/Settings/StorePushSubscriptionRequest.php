<?php

namespace App\Http\Requests\Settings;

use App\Domains\Notifications\Support\PushEndpoint;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StorePushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'url:https', 'max:2048', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && ! PushEndpoint::isAllowed($value)) {
                    $fail('Este navegador usa un servicio de avisos que SAM no admite.');
                }
            }],
            'keys.p256dh' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && ! PushEndpoint::isValidPublicKey($value)) {
                    $fail('La llave de este navegador no es válida. Vuelve a activar los avisos.');
                }
            }],
            'keys.auth' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && ! PushEndpoint::isValidAuthToken($value)) {
                    $fail('La llave de este navegador no es válida. Vuelve a activar los avisos.');
                }
            }],
            'content_encoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
        ];
    }
}
