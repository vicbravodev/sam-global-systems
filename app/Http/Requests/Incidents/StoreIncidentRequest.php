<?php

namespace App\Http\Requests\Incidents;

use App\Http\Requests\Concerns\ResolvesCurrentTeam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIncidentRequest extends FormRequest
{
    use ResolvesCurrentTeam;

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
            'incident_type_id' => ['required', 'integer', 'exists:incident_types,id'],
            'incident_priority_id' => ['nullable', 'integer', 'exists:incident_priorities,id'],
            // Activo y conductor deben ser del tenant de la ruta.
            'asset_id' => ['nullable', 'integer', Rule::exists('assets', 'id')->where('team_id', $this->currentTeamId())],
            'driver_id' => ['nullable', 'integer', Rule::exists('drivers', 'id')->where('team_id', $this->currentTeamId())],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
