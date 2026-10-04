<?php

namespace App\Http\Requests\TenantConfig;

use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Incidents\Support\EscalationLadder;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;

/**
 * Configuración del monitoreo HOS (spec §3.2 y §3.12). El resolver
 * (`HosMonitoringConfig::fromArray`) tolera basura en silencio, así que la
 * validación estricta vive aquí: nada de "false" como texto ni negativos,
 * escalones crecientes con al menos {@see EscalationLadder::MIN_GAP_MINUTES}
 * min entre sí y un solo incidente, al final.
 */
class UpdateHosMonitoringConfigRequest extends FormRequest
{
    public const int MAX_LADDER_STEPS = 8;

    public function authorize(): bool
    {
        return $this->user()?->can('updateConfig', HosDriverState::class) ?? false;
    }

    /**
     * Una unidad del team de la ruta, no borrada.
     */
    public static function teamAssetRule(mixed $team): Exists
    {
        return Rule::exists('assets', 'id')
            ->where('team_id', $team instanceof Team ? $team->id : 0)
            ->whereNull('deleted_at');
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $asset = self::teamAssetRule($this->route('current_team'));

        $rules = [
            'tag_ids' => ['present', 'array', 'max:100'],
            'tag_ids.*' => ['required', 'string', 'max:64', 'distinct'],
            'included_asset_ids' => ['present', 'array', 'max:2000'],
            'included_asset_ids.*' => ['required', 'integer', 'distinct', $asset],
            'excluded_asset_ids' => ['present', 'array', 'max:2000'],
            'excluded_asset_ids.*' => ['required', 'integer', 'distinct', $asset],
            'situations' => ['required', 'array:'.implode(',', HosMonitoringConfig::CONFIGURABLE_SITUATIONS)],
            'lead_minutes' => ['required', 'array', 'min:1', 'max:5'],
            'lead_minutes.*' => ['required', 'integer', 'min:0', 'max:240', 'distinct'],
            'cycle_lead_hours' => ['required', 'array', 'min:1', 'max:4'],
            'cycle_lead_hours.*' => ['required', 'integer', 'min:1', 'max:69', 'distinct'],
            'rest_complete_nudge_minutes' => ['required', 'array', 'min:1', 'max:4'],
            'rest_complete_nudge_minutes.*' => ['required', 'integer', 'min:1', 'max:180', 'distinct'],
            'rest_complete_expire_minutes' => ['required', 'integer', 'min:2', 'max:240'],
            'ladder' => ['required', 'array', 'min:1', 'max:'.self::MAX_LADDER_STEPS],
            'ladder.*' => ['required', 'array:after_minutes,channels,escalate'],
            'ladder.*.after_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'ladder.*.channels' => ['present', 'array', 'max:4'],
            // Sin `distinct`: con dos comodines compara contra TODOS los escalones
            // (la app va en el escalón 0 y en el 1). Los repetidos dentro de un
            // escalón se quitan al guardar.
            'ladder.*.channels.*' => ['required', 'string', Rule::in(HosMonitoringConfig::DRIVER_CHANNELS)],
            'ladder.*.escalate' => ['nullable', 'string', Rule::in(['incident'])],
        ];

        foreach (HosMonitoringConfig::CONFIGURABLE_SITUATIONS as $situation) {
            // `boolean` acepta true/false/1/0/"1"/"0"; rechaza "false" y "true".
            $rules["situations.{$situation}"] = ['required', 'boolean'];
        }

        return $rules;
    }

    /**
     * Mensajes sin llaves de campo: el primero se muestra tal cual en el aviso.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $unit = 'Una de las unidades elegidas ya no existe o no es de tu empresa. Quítala y vuelve a guardar.';

        return [
            'tag_ids.*' => 'Una de las etiquetas elegidas no es válida. Quítala y vuelve a guardar.',
            'included_asset_ids.*' => $unit,
            'excluded_asset_ids.*' => $unit,
            'ladder.*.channels.*' => 'Uno de los canales no sirve para avisarle al chofer. Elige app de Samsara, WhatsApp, SMS o llamada.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // Las reglas cruzadas sólo tienen sentido sobre valores bien formados.
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->checkUnits($validator);
                $this->checkRestComplete($validator);
                $this->checkLadder($validator);
            },
        ];
    }

    private function checkUnits(Validator $validator): void
    {
        $included = array_map(self::int(...), (array) $this->input('included_asset_ids', []));
        $excluded = array_map(self::int(...), (array) $this->input('excluded_asset_ids', []));

        if (array_intersect($included, $excluded) !== []) {
            $validator->errors()->add('excluded_asset_ids', 'Una unidad no puede estar en "siempre entran" y en "nunca entran" a la vez.');
        }
    }

    private function checkRestComplete(Validator $validator): void
    {
        $nudges = array_map(self::int(...), (array) $this->input('rest_complete_nudge_minutes', []));
        $lastNudge = $nudges === [] ? 0 : max($nudges);

        if (self::int($this->input('rest_complete_expire_minutes')) <= $lastNudge) {
            $validator->errors()->add(
                'rest_complete_expire_minutes',
                "Debe ser mayor que el último recordatorio para retomar ({$lastNudge} min); si no, ese recordatorio nunca sale.",
            );
        }
    }

    private function checkLadder(Validator $validator): void
    {
        $ladder = array_values(array_filter((array) $this->input('ladder', []), 'is_array'));
        $last = count($ladder) - 1;
        $previous = null;
        $notifies = false;

        foreach ($ladder as $index => $step) {
            $after = self::int($step['after_minutes'] ?? null);
            $channels = (array) ($step['channels'] ?? []);
            $escalate = ($step['escalate'] ?? null) === 'incident';

            if ($index === 0 && $after !== 0) {
                $validator->errors()->add('ladder.0.after_minutes', 'El primer escalón sale al llegar al límite: debe ser 0 min.');
            }

            if ($previous !== null && $after - $previous < EscalationLadder::MIN_GAP_MINUTES) {
                $validator->errors()->add("ladder.{$index}.after_minutes", 'Deja al menos '.EscalationLadder::MIN_GAP_MINUTES.' min después del escalón anterior.');
            }

            if ($escalate && $index !== $last) {
                $validator->errors()->add("ladder.{$index}.escalate", 'El incidente sólo puede ser el último escalón.');
            }

            if ($escalate && $channels !== []) {
                $validator->errors()->add("ladder.{$index}.channels", 'El escalón del incidente avisa a tu equipo, no al chofer: quítale los canales.');
            }

            if (! $escalate && $channels === []) {
                $validator->errors()->add("ladder.{$index}.channels", 'Elige al menos un canal para este escalón.');
            }

            $notifies = $notifies || (! $escalate && $channels !== []);
            $previous = $after;
        }

        if (! $notifies) {
            $validator->errors()->add('ladder', 'La escalera necesita al menos un escalón que le avise al chofer.');
        }
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
