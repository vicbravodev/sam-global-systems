<?php

namespace App\Http\Controllers\TenantConfig;

use App\Domains\Drivers\Actions\BuildHosConfigForm;
use App\Domains\Drivers\Actions\ListHosTags;
use App\Domains\Drivers\Actions\PreviewHosEnrollment;
use App\Domains\Drivers\Actions\SaveHosMonitoringConfig;
use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Http\Controllers\Controller;
use App\Http\Requests\TenantConfig\PreviewHosEnrollmentRequest;
use App\Http\Requests\TenantConfig\UpdateHosMonitoringConfigRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuración del monitoreo HOS (sección `?seccion=hos`). La autorización
 * (feature `hos_monitoring` + permiso) es `HosMonitoringPolicy`; el guardado
 * la revisa en el FormRequest, antes de validar.
 */
class HosMonitoringConfigController extends Controller
{
    public function show(Request $request, Team $current_team, BuildHosConfigForm $form): JsonResponse
    {
        $this->authorize('viewConfig', HosDriverState::class);

        $canManage = $request->user()?->can('updateConfig', HosDriverState::class) ?? false;

        return response()->json(['data' => $form->execute($current_team->id, $canManage)]);
    }

    public function update(UpdateHosMonitoringConfigRequest $request, Team $current_team, SaveHosMonitoringConfig $save, BuildHosConfigForm $form): JsonResponse
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $save->execute($current_team->id, $validated, $request->user()?->id, $request->ip(), $request->userAgent());

        return response()->json(['data' => $form->execute($current_team->id, true)]);
    }

    public function tags(Team $current_team, ListHosTags $listTags): JsonResponse
    {
        $this->authorize('viewConfig', HosDriverState::class);

        $result = $listTags->execute($current_team->id);

        return response()->json([
            'data' => $result['tags'],
            'meta' => ['failed' => $result['failed'], 'hasIntegration' => $result['hasIntegration']],
        ]);
    }

    public function preview(PreviewHosEnrollmentRequest $request, Team $current_team, PreviewHosEnrollment $preview): JsonResponse
    {
        /** @var array<string, mixed> $selection */
        $selection = $request->validated();

        $draft = HosMonitoringConfig::fromArray($selection, (array) config('hos.defaults'));

        $result = $preview->execute($current_team->id, $draft);

        // `skipped` es un mapa razón → conteo: vacío sigue siendo `{}`, no `[]`.
        return response()->json(['data' => ['skipped' => (object) $result['skipped']] + $result]);
    }
}
