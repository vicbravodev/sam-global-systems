<?php

namespace App\Providers;

use App\Support\PipelineTrace;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configurePipelineTrace();
    }

    /**
     * Ninguna unidad de trabajo queda sin `trace_id`: cada tarea del scheduler
     * abre la suya (y la propaga a lo que despache), y un job que llega sin
     * traza (despachado desde la UI, un comando o un barrido) abre una propia.
     * Los jobs del pipeline la heredan del payload o la adoptan del evento
     * persistido. Ver App\Support\PipelineTrace.
     */
    private function configurePipelineTrace(): void
    {
        // Corre después de que el worker rehidrate el Context del payload
        // (ContextServiceProvider se registra antes que los de la app).
        Queue::before(function (): void {
            if (PipelineTrace::id() === null) {
                PipelineTrace::begin(TenantContext::id());
            }
        });

        Event::listen(ScheduledTaskStarting::class, function (): void {
            PipelineTrace::begin(null);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // N+1 guard: outside production, touching a relation that was not
        // eager-loaded on a multi-row result throws, so tests catch it.
        Model::preventLazyLoading(! app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
