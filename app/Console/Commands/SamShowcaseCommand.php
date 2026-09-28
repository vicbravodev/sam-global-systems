<?php

namespace App\Console\Commands;

use Database\Seeders\Showcase\ShowcaseReplayer;
use Database\Seeders\Showcase\ShowcaseSeeder;
use Illuminate\Console\Command;

/**
 * Puebla todas las pantallas de SAM con una simulación coherente de
 * operación, encima de los datos reales del tenant. Aditivo y
 * re-ejecutable; nunca en producción; sin efectos externos (ver
 * `database/seeders/Showcase/ShowcaseSeeder.php`).
 *
 *   php artisan sam:showcase                          # ServiExpress JC, 90 días
 *   php artisan sam:showcase --team=otro --days=30
 *   php artisan sam:showcase --with-extra-tenants     # + 3 tenants para /admin
 *   php artisan sam:showcase --replay=5               # + 5 eventos reales por el pipeline
 */
class SamShowcaseCommand extends Command
{
    protected $signature = 'sam:showcase
        {--team=serviexpress-jc : Slug del tenant a poblar (se crea si no existe)}
        {--days=90 : Días de historia a simular (1-365)}
        {--with-extra-tenants : Añade 3 tenants con suscripciones en distintos estados para la consola de admin}
        {--replay=0 : Reinyecta N eventos reales de Samsara (fixtures) por el pipeline real, en síncrono}';

    protected $description = 'Siembra datos de demostración coherentes en todas las pantallas (sólo fuera de producción).';

    public function handle(): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('sam:showcase siembra datos simulados y nunca corre en producción.');

            return self::FAILURE;
        }

        $started = microtime(true);
        $seeder = (new ShowcaseSeeder)->configure(
            teamSlug: (string) $this->option('team'),
            days: (int) $this->option('days'),
            extraTenants: (bool) $this->option('with-extra-tenants'),
        );
        $seeder->setContainer($this->laravel)->setCommand($this);
        $seeder->__invoke();

        $replay = (int) $this->option('replay');

        if ($replay > 0) {
            $result = app(ShowcaseReplayer::class)->replay((string) $this->option('team'), $replay, $this);
            $this->info("Replay: {$result['replayed']} evento(s) reales por el pipeline, {$result['jobs']} job(s) ejecutados en síncrono, {$result['discarded']} descartados (notificaciones/red).");
        }

        foreach ($seeder->summary() as $slug => $counts) {
            $this->newLine();
            $this->line("<options=bold>{$slug}</> — filas nuevas");
            $this->table(['tabla', 'filas'], collect($counts)->map(fn ($n, $t) => [$t, $n])->values()->all());
        }

        $this->info(sprintf('Showcase listo en %.1f s. Entra con cualquier miembro del tenant (los creados por el showcase usan la contraseña "password").', microtime(true) - $started));

        return self::SUCCESS;
    }
}
