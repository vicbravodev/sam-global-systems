<?php

namespace Database\Seeders\Showcase\Support;

use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Estado compartido por los pasos del showcase de UN tenant: el team, sus
 * usuarios por rol, la flota con la que trabajar y el reloj de referencia.
 *
 * `$light` marca a los tenants extra de la consola de administración: los
 * pasos siembran una versión reducida (menos días, menos volumen).
 */
final class ShowcaseContext
{
    /** Prefijo común de toda clave idempotente del showcase. */
    public const KEY_PREFIX = 'showcase:';

    public readonly CarbonImmutable $now;

    /** @var array<string, User> rol lógico (admin, supervisor, monitor, analyst, viewer) => usuario */
    public array $users = [];

    /** @var Collection<int, Asset> */
    public Collection $assets;

    /** @var Collection<int, Driver> */
    public Collection $drivers;

    public ?IntegrationProvider $provider = null;

    /** Integración activa principal del tenant (real o simulada). */
    public ?TenantIntegration $integration = null;

    /** @var array<int, array{0: string, 1: float, 2: float}> asset_id => [dirección, lat, lng] de su última posición conocida */
    public array $homes = [];

    /** @var array<int, int> asset_id => driver_id del conductor principal vigente */
    public array $primaryDriver = [];

    /** @var array<string, int> código de prioridad => SLA efectivo en segundos */
    public array $slaSeconds = ['low' => 14_400, 'medium' => 3_600, 'high' => 1_800, 'critical' => 300];

    /**
     * El tenant ya tenía eventos propios (datos reales de Samsara) antes del
     * showcase. En ese caso la configuración que altera el comportamiento en
     * vivo (reglas, escalamientos) se siembra desactivada.
     */
    public bool $hasRealData = false;

    /** Plan y estado de suscripción a sembrar si el tenant no tiene. */
    public string $subscriptionPlan = 'pro';

    public string $subscriptionStatus = 'active';

    /** @var array<string, int> tabla => filas creadas en esta corrida */
    private array $created = [];

    public function __construct(
        public readonly Team $team,
        public readonly int $days,
        public readonly bool $light = false,
        public readonly ?Command $command = null,
        ?CarbonImmutable $now = null,
    ) {
        $this->now = $now ?? CarbonImmutable::now();
        $this->assets = new Collection;
        $this->drivers = new Collection;
    }

    public function key(string ...$parts): string
    {
        return self::KEY_PREFIX.$this->team->id.':'.implode(':', $parts);
    }

    public function random(string ...$parts): ShowcaseRandom
    {
        return ShowcaseRandom::forKey($this->key(...$parts));
    }

    public function user(string $role): User
    {
        $user = $this->users[$role] ?? $this->users['admin'] ?? reset($this->users);

        if ($user === false) {
            throw new LogicException('El showcase aún no tiene usuarios: siembra el equipo antes de pedir uno.');
        }

        return $user;
    }

    /**
     * @return array<int, User>
     */
    public function operators(): array
    {
        return array_values(array_filter([
            $this->users['monitor'] ?? null,
            $this->users['supervisor'] ?? null,
            $this->users['admin'] ?? null,
        ]));
    }

    /**
     * Primer día del periodo simulado (a medianoche).
     */
    public function startDay(): CarbonImmutable
    {
        return $this->now->subDays($this->days - 1)->startOfDay();
    }

    public function count(string $table, int $rows = 1): void
    {
        $this->created[$table] = ($this->created[$table] ?? 0) + $rows;
    }

    /**
     * @return array<string, int>
     */
    public function createdCounts(): array
    {
        ksort($this->created);

        return $this->created;
    }

    public function info(string $message): void
    {
        $this->command?->line("  <fg=gray>[{$this->team->slug}]</> {$message}");
    }
}
