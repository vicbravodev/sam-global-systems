<?php

namespace App\Http\Requests\Incidents;

use App\Domains\Incidents\Enums\EvidenceSourceType;
use App\Domains\Incidents\Enums\EvidenceType;
use App\Http\Requests\Concerns\ResolvesCurrentTeam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIncidentEvidenceRequest extends FormRequest
{
    use ResolvesCurrentTeam;

    /**
     * Tabla que referencia `source_reference_id` según `source_type`: la fila
     * debe ser del tenant de la ruta.
     */
    private const SOURCE_TABLES = [
        'event_context' => 'event_context_snapshots',
        'event_media' => 'event_media_contexts',
        'raw_event' => 'raw_events',
        'normalized_event' => 'normalized_events',
        'ai_evaluation' => 'ai_event_evaluations',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'evidence_type' => ['required', 'string', Rule::in(array_column(EvidenceType::cases(), 'value'))],
            'source_type' => ['required', 'string', Rule::in(array_column(EvidenceSourceType::cases(), 'value'))],
            'source_reference_id' => ['nullable', 'integer', ...$this->sourceReferenceRules()],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'file' => ['nullable', 'file', 'max:51200'],
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function sourceReferenceRules(): array
    {
        $table = self::SOURCE_TABLES[(string) $this->input('source_type')] ?? null;

        return $table === null
            ? []
            : [Rule::exists($table, 'id')->where('team_id', $this->currentTeamId())];
    }
}
