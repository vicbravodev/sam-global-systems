<?php

namespace App\Domains\AI\Commands;

use App\Contracts\ObjectStorage;
use App\Domains\AI\Actions\RecordOperatorVerdict;
use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\OperatorVerdict;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIInferenceLog;
use App\Domains\Context\Enums\MediaRetrievalStatus;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Models\EventMediaContext;
use App\Infrastructure\AI\Clef\ClefStateBuilder;
use App\Models\User;
use App\Support\SystemLog;
use App\Support\TeamMembers;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Etiquetado humano a ciegas para la base de verdad de la medición Clef vs
 * GPT. Nunca muestra lo que opinó ningún modelo: sólo la entrada del evento
 * (sin el veredicto visual de otro modelo ni feedback previo). Graba por el
 * mismo camino que el operador en la UI (RecordOperatorVerdict), con
 * auditoría.
 */
class LabelEventsCommand extends Command
{
    public const string NOTE = 'etiquetado:baseline';

    private const array CHOICES = ['r' => 'real', 'f' => 'falso positivo', 's' => 'saltar', 'q' => 'salir'];

    private const array SHOWN_KEYS = ['normalized_event', 'asset', 'driver', 'location', 'telemetry', 'context_signals', 'recent_history', 'incidents'];

    protected $signature = 'ai:label-events
        {--team= : Team id (obligatorio)}
        {--user= : Email o id del usuario que etiqueta (obligatorio, miembro del team)}
        {--limit=100 : Máximo de eventos en esta sesión}
        {--since= : Sólo eventos evaluados desde esta fecha (Y-m-d)}';

    protected $description = 'Etiqueta eventos a ciegas (real / falso positivo) para medir a GPT y Clef';

    public function handle(RecordOperatorVerdict $record, ObjectStorage $storage, ClefStateBuilder $stateBuilder): int
    {
        $teamId = (int) $this->option('team');
        $userOption = trim((string) $this->option('user'));
        $user = ctype_digit($userOption) ? User::find((int) $userOption) : User::findByEmail($userOption);

        if ($teamId <= 0 || $user === null || ! TeamMembers::isMember($teamId, $user->id)) {
            $this->error('--team y --user son obligatorios y el usuario debe ser miembro del team.');

            return self::FAILURE;
        }

        return TenantContext::for($teamId, function () use ($teamId, $user, $record, $storage, $stateBuilder): int {
            $sample = $this->stratifiedSample(max(1, (int) $this->option('limit')));

            if ($sample->isEmpty()) {
                $this->info('No hay eventos pendientes de etiquetar.');

                return self::SUCCESS;
            }

            foreach ($sample as $index => $evaluation) {
                $this->newLine();
                $this->line(sprintf('<options=bold>Evento %d de %d</>', $index + 1, $sample->count()));
                $this->renderEvent($evaluation, $storage, $stateBuilder);

                $answer = $this->choice('Veredicto', self::CHOICES);

                if ($answer === 'q') {
                    break;
                }

                if ($answer === 's') {
                    continue;
                }

                $verdict = $answer === 'r' ? OperatorVerdict::Confirmed : OperatorVerdict::FalsePositive;
                $record->execute($teamId, $evaluation->normalized_event_id, $verdict, $user->id, self::NOTE);

                SystemLog::ok(
                    'ai.label.recorded',
                    input: ['evaluation_id' => $evaluation->id, 'normalized_event_id' => $evaluation->normalized_event_id, 'user_id' => $user->id],
                    result: ['verdict' => $verdict->value],
                );
            }

            return self::SUCCESS;
        });
    }

    /**
     * Última versión de cada evento, sin veredicto, repartida en round-robin
     * por (clasificación de GPT, tipo de evento) para no etiquetar sólo pánicos.
     *
     * @return Collection<int, AIEventEvaluation>
     */
    private function stratifiedSample(int $limit): Collection
    {
        $since = $this->option('since') !== null ? Carbon::parse($this->option('since'))->startOfDay() : null;

        $candidates = AIEventEvaluation::query()
            ->with('normalizedEvent:id,event_type_id')
            ->whereIn('evaluation_mode', [EvaluationMode::AiText, EvaluationMode::Hybrid])
            ->when($since !== null, fn ($q) => $q->where('created_at', '>=', $since))
            ->orderByDesc('evaluation_version')
            ->orderByDesc('id')
            ->get()
            ->groupBy('normalized_event_id')
            // Un veredicto en cualquier versión ya etiqueta el evento.
            ->reject(fn ($versions): bool => $versions->contains(fn (AIEventEvaluation $e): bool => $e->operator_verdict !== null))
            ->map(fn ($versions): ?AIEventEvaluation => $versions->first())
            ->filter()
            ->values();

        /** @var Collection<int, Collection<int, AIEventEvaluation>> $groups */
        $groups = $candidates
            ->groupBy(fn (AIEventEvaluation $e): string => ($e->classification->value ?? 'none').'|'.($e->normalizedEvent->event_type_id ?? 0))
            ->map(fn (Collection $group): Collection => $group->values())
            ->values();

        $picked = collect();

        for ($round = 0; $picked->count() < $limit && $groups->contains(fn (Collection $g): bool => $g->has($round)); $round++) {
            foreach ($groups as $group) {
                if ($picked->count() < $limit && $group->has($round)) {
                    $picked->push($group[$round]);
                }
            }
        }

        return $picked->values();
    }

    private function renderEvent(AIEventEvaluation $evaluation, ObjectStorage $storage, ClefStateBuilder $stateBuilder): void
    {
        $raw = AIInferenceLog::query()->where('evaluation_id', $evaluation->id)->orderBy('id')->value('input_snapshot_json');
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;
        $snapshot = $stateBuilder->fromSnapshot(is_array($raw) ? $raw : []);

        $rows = [];

        foreach (self::SHOWN_KEYS as $key) {
            $value = $snapshot[$key] ?? null;

            if ($value !== null && $value !== [] && $value !== '') {
                $rows[] = [$key, (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)];
            }
        }

        $this->table(['Dato', 'Valor'], $rows);

        $media = EventMediaContext::query()
            ->where('normalized_event_id', $evaluation->normalized_event_id)
            ->whereIn('media_type', [MediaType::Image, MediaType::Snapshot])
            ->where('retrieval_status', MediaRetrievalStatus::Ready)
            ->whereNotNull('storage_path')
            ->limit(4)
            ->get();

        foreach ($media as $item) {
            $this->line('Imagen (15 min): '.$storage->temporaryUrl((string) $item->storage_path, now()->addMinutes(15)));
        }
    }
}
