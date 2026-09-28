<?php

namespace App\Http\Controllers\Assets;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Assets\Actions\SetAssetMonitoring;
use App\Domains\Assets\Enums\AssetMonitoringState;
use App\Domains\Assets\Models\Asset;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * El cliente decide qué unidades vigila SAM (decisión 2026-09-28): enciende
 * o apaga la vigilancia por unidad o en lote. Tope suave: encender más allá
 * del cupo se permite y se avisa que se cobra como extra por día.
 */
class AssetMonitoringController extends Controller
{
    public function __construct(
        private readonly AuthorizeAction $authorizeAction,
        private readonly SetAssetMonitoring $setMonitoring,
    ) {}

    public function update(Request $request, Team $current_team, Asset $asset): RedirectResponse
    {
        // Comparación explícita de team además del scope global (§2.1 punto 6).
        abort_if((int) $asset->team_id !== (int) $current_team->id, 404);
        $this->authorizeManage($request, $current_team);

        $data = $request->validate([
            'state' => ['required', Rule::enum(AssetMonitoringState::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->setMonitoring->execute(
            $asset,
            AssetMonitoringState::from($data['state']),
            $request->user(),
            $data['reason'] ?? null,
        );

        return back()->with('status', $this->message($result));
    }

    public function bulk(Request $request, Team $current_team): RedirectResponse
    {
        $this->authorizeManage($request, $current_team);

        $data = $request->validate([
            'state' => ['required', Rule::enum(AssetMonitoringState::class)],
            'asset_ids' => ['required', 'array', 'min:1', 'max:500'],
            'asset_ids.*' => ['integer'],
        ]);

        $state = AssetMonitoringState::from($data['state']);

        // Sólo activos del tenant de la ruta: un id ajeno simplemente no existe.
        $assets = Asset::query()
            ->where('team_id', $current_team->id)
            ->whereKey($data['asset_ids'])
            ->get();

        $last = null;
        $changed = 0;

        foreach ($assets as $asset) {
            $last = $this->setMonitoring->execute($asset, $state, $request->user());
            $changed += $last['changed'] ? 1 : 0;
        }

        $verb = $state === AssetMonitoringState::Monitored ? 'encendidas' : 'apagadas';
        $message = "{$changed} ".($changed === 1 ? 'unidad' : 'unidades')." {$verb}.";

        if ($last !== null && $last['over_cap']) {
            $message .= " Estás por encima de tu tope de {$last['cap']}: el excedente se cobra como extra por día.";
        }

        return back()->with('status', $message);
    }

    private function authorizeManage(Request $request, Team $team): void
    {
        abort_unless(
            $request->user() !== null
                && $this->authorizeAction->execute($request->user(), 'assets.manage', $team),
            403,
        );
    }

    /**
     * @param  array{asset: Asset, changed: bool, over_cap: bool, monitored: int, cap: int|null}  $result
     */
    private function message(array $result): string
    {
        $asset = $result['asset'];
        $state = $asset->monitoring_state;

        if (! $result['changed']) {
            return "{$asset->name} ya estaba en \"{$state->label()}\".";
        }

        $message = match ($state) {
            AssetMonitoringState::Monitored => "{$asset->name} ahora está vigilada ({$result['monitored']}".($result['cap'] !== null ? " de {$result['cap']}" : '').').',
            AssetMonitoringState::Excluded => "{$asset->name} quedó excluida: SAM deja de vigilarla y de cobrarla desde hoy.",
            AssetMonitoringState::Pending => "{$asset->name} volvió a pendiente.",
        };

        if ($result['over_cap']) {
            $message .= " Estás por encima de tu tope de {$result['cap']}: esta unidad se cobra como extra por cada día encendida.";
        }

        return $message;
    }
}
