<?php

namespace App\Http\Requests\TenantConfig;

use App\Domains\Drivers\Models\HosDriverState;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Selección en borrador para la vista previa del conjunto HOS: sólo lo que
 * decide quién entra (etiquetas y unidades del team).
 */
class PreviewHosEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewConfig', HosDriverState::class) ?? false;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $asset = UpdateHosMonitoringConfigRequest::teamAssetRule($this->route('current_team'));

        return [
            'tag_ids' => ['present', 'array', 'max:100'],
            'tag_ids.*' => ['required', 'string', 'max:64'],
            'included_asset_ids' => ['present', 'array', 'max:2000'],
            'included_asset_ids.*' => ['required', 'integer', $asset],
            'excluded_asset_ids' => ['present', 'array', 'max:2000'],
            'excluded_asset_ids.*' => ['required', 'integer', $asset],
        ];
    }
}
