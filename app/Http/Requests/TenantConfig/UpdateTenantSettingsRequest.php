<?php

namespace App\Http\Requests\TenantConfig;

use App\Domains\TenantConfig\Enums\SettingGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantSettingsRequest extends FormRequest
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
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.setting_key' => ['required', 'string', 'max:255'],
            'settings.*.setting_group' => ['required', 'string', Rule::enum(SettingGroup::class)],
            'settings.*.value_type' => ['required', 'string', 'in:string,number,boolean,json,array'],
            'settings.*.value' => ['required'],
        ];
    }
}
