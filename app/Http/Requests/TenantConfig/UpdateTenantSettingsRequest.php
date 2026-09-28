<?php

namespace App\Http\Requests\TenantConfig;

use App\Domains\Context\Actions\FetchLiveLocationForEvent;
use App\Domains\Notifications\Listeners\NotifyOnIncidentCreated;
use App\Domains\TenantConfig\Enums\SettingGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

    /**
     * D-07: el endpoint genérico de settings no debe aceptar basura en las
     * keys con rango conocido (p. ej. GPS staleness de -5 segundos).
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $settings = $this->input('settings');

                if (! is_array($settings)) {
                    return;
                }

                foreach ($settings as $index => $setting) {
                    if (! is_array($setting)) {
                        continue;
                    }

                    if (($setting['setting_key'] ?? null) === NotifyOnIncidentCreated::SETTING_MIN_SEVERITY
                        && ! in_array($setting['value'] ?? null, ['low', 'medium', 'high', 'critical'], true)) {
                        $validator->errors()->add(
                            "settings.{$index}.value",
                            'Elige un nivel de gravedad válido: baja, media, alta o crítica.',
                        );

                        continue;
                    }

                    if (($setting['setting_key'] ?? null) !== FetchLiveLocationForEvent::SETTING_KEY) {
                        continue;
                    }

                    $value = $setting['value'] ?? null;

                    if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
                        $validator->errors()->add(
                            "settings.{$index}.value",
                            'La antigüedad máxima de la ubicación debe ser de al menos 1 segundo.',
                        );
                    }
                }
            },
        ];
    }
}
