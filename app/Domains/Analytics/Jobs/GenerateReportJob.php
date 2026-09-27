<?php

namespace App\Domains\Analytics\Jobs;

use App\Domains\Analytics\Actions\GenerateReport;
use App\Domains\Analytics\Enums\ReportOutputFormat;
use App\Domains\Analytics\Enums\ReportRequestedByType;
use App\Domains\Analytics\Models\ReportDefinition;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 90];

    public function __construct(
        public int $reportDefinitionId,
        public int $teamId,
        public string $outputFormat,
        public string $requestedByType,
        public ?int $requestedById = null,
        public ?array $filters = null,
    ) {
        $this->onQueue('analytics');
    }

    public function handle(GenerateReport $action): void
    {
        // ReportDefinition no lleva BelongsToTenant (team_id nullable: hay
        // definiciones de plataforma). Al recibir el id de la definición y el
        // teamId por separado, hay que comprobar que concuerdan: si no, se
        // generaría el reporte de un tenant con la definición de otro.
        $definition = ReportDefinition::query()
            ->whereKey($this->reportDefinitionId)
            ->where(fn ($query) => $query->whereNull('team_id')->orWhere('team_id', $this->teamId))
            ->firstOrFail();

        $action->execute(
            definition: $definition,
            teamId: $this->teamId,
            format: ReportOutputFormat::from($this->outputFormat),
            requestedBy: ReportRequestedByType::from($this->requestedByType),
            requestedById: $this->requestedById,
            filters: $this->filters,
        );
    }
}
