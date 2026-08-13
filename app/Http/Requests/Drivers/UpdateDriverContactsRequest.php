<?php

namespace App\Http\Requests\Drivers;

use App\Domains\Drivers\Enums\ContactType;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateDriverContactsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'contacts' => ['required', 'array', 'min:1'],
            'contacts.*.contact_type' => ['required', 'string', Rule::enum(ContactType::class)],
            'contacts.*.label' => ['nullable', 'string', 'max:255'],
            'contacts.*.value' => ['required', 'string', 'max:255'],
            'contacts.*.is_primary' => ['sometimes', 'boolean'],
            'contacts.*.is_emergency' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ($this->input('contacts', []) as $i => $contact) {
                $contactType = $contact['contact_type'] ?? null;

                if (! is_string($contact['value'] ?? null)) {
                    continue;
                }

                $value = (string) ($contact['value'] ?? '');

                if ($contactType === ContactType::MobilePhone->value && ! PhoneNumber::isE164($value)) {
                    $validator->errors()->add(
                        "contacts.{$i}.value",
                        'El teléfono móvil debe estar en formato internacional E.164, por ejemplo +5215555550123.',
                    );
                }
            }
        });
    }
}
