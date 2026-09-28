<?php

namespace App\Providers;

use App\Support\PipelineTrace;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

/**
 * Telescope SÓLO en local: AppServiceProvider lo registra únicamente cuando
 * `app()->environment('local')` y el paquete (dev) está instalado, así que
 * producción y CI nunca lo cargan. Aun así el acceso se cierra fuera de local:
 * Telescope guarda datos de TODOS los tenants.
 *
 * Cada entrada lleva `trace:{trace_id}` y `team:{team_id}` del Context
 * (App\Support\PipelineTrace): buscar la etiqueta `trace:...` en el dashboard
 * devuelve el recorrido de un evento por el pipeline.
 */
class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Colas de alta frecuencia (el feed de telemática corre cada 5 s por
     * tenant): no se registran salvo fallos y excepciones.
     *
     * @var list<string>
     */
    public const array QUIET_QUEUES = ['telematics'];

    /**
     * Tareas del scheduler de alta frecuencia, por su nombre (`->name()`).
     *
     * @var list<string>
     */
    public const array QUIET_SCHEDULED_TASKS = ['telematics:dispatch-feeds'];

    /**
     * Jobs de las propias herramientas de observabilidad.
     *
     * @var list<string>
     */
    private const array IGNORED_JOB_PREFIXES = ['Laravel\\Horizon\\', 'Laravel\\Telescope\\'];

    /**
     * El proceso está ejecutando un job o tarea "silenciosa".
     */
    private static bool $quiet = false;

    public function register(): void
    {
        $this->hideSensitiveRequestDetails();

        Telescope::tag(static fn (): array => self::traceTags());
        Telescope::filter(static fn (IncomingEntry $entry): bool => self::shouldRecord($entry));

        $this->silenceHighFrequencyWork();
    }

    /**
     * @return list<string>
     */
    public static function traceTags(): array
    {
        $tags = [];

        if (($traceId = PipelineTrace::id()) !== null) {
            $tags[] = 'trace:'.$traceId;
        }

        if (is_int($teamId = Context::get(PipelineTrace::TEAM_KEY))) {
            $tags[] = 'team:'.$teamId;
        }

        return $tags;
    }

    public static function shouldRecord(IncomingEntry $entry): bool
    {
        if ($entry->isReportableException() || $entry->isFailedJob()) {
            return true;
        }

        if (self::$quiet) {
            return false;
        }

        if ($entry->type === EntryType::JOB) {
            $name = (string) ($entry->content['name'] ?? '');

            return ! in_array($entry->content['queue'] ?? null, self::QUIET_QUEUES, true)
                && ! str_starts_with($name, self::IGNORED_JOB_PREFIXES[0])
                && ! str_starts_with($name, self::IGNORED_JOB_PREFIXES[1]);
        }

        if ($entry->type === EntryType::SCHEDULED_TASK) {
            return ! in_array($entry->content['description'] ?? null, self::QUIET_SCHEDULED_TASKS, true);
        }

        return true;
    }

    /**
     * Mientras corre un job de una cola silenciosa o una tarea programada de
     * alta frecuencia, sus queries, logs y eventos tampoco se registran.
     */
    private function silenceHighFrequencyWork(): void
    {
        Event::listen(JobProcessing::class, static function (JobProcessing $event): void {
            self::$quiet = in_array($event->job->getQueue(), self::QUIET_QUEUES, true);
        });
        Event::listen([JobProcessed::class, JobFailed::class], static function (): void {
            self::$quiet = false;
        });

        Event::listen(ScheduledTaskStarting::class, static function (ScheduledTaskStarting $event): void {
            self::$quiet = in_array($event->task->description, self::QUIET_SCHEDULED_TASKS, true);
        });
        Event::listen([ScheduledTaskFinished::class, ScheduledTaskFailed::class], static function (): void {
            self::$quiet = false;
        });
    }

    /**
     * Firmas de webhooks, tokens y cookies nunca se guardan, ni en local.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        Telescope::hideRequestParameters(['_token', 'password', 'password_confirmation', 'signature']);

        Telescope::hideRequestHeaders([
            'authorization',
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
            'x-samsara-signature',
            'x-twilio-signature',
        ]);
    }

    /**
     * Acceso sólo en local, sin excepciones por usuario: el dashboard muestra
     * datos de todos los tenants.
     */
    protected function authorization(): void
    {
        $this->gate();

        Telescope::auth(static fn (Request $request): bool => app()->environment('local')
            && Gate::check('viewTelescope', [$request->user()]));
    }

    protected function gate(): void
    {
        Gate::define('viewTelescope', static fn ($user = null): bool => app()->environment('local'));
    }
}
