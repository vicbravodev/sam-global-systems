<?php

namespace Database\Seeders;

use App\Domains\Decisions\Models\DecisionOutcome;
use Illuminate\Database\Seeder;

class DecisionOutcomeSeeder extends Seeder
{
    public function run(): void
    {
        $outcomes = [
            ['code' => 'IGNORE', 'name' => 'Ignorar', 'description' => 'Descarta el evento por completo.', 'is_terminal' => true],
            ['code' => 'LOG_ONLY', 'name' => 'Solo registrar', 'description' => 'Registra el evento sin ninguna otra acción.', 'is_terminal' => true],
            ['code' => 'ALERT', 'name' => 'Alerta', 'description' => 'Muestra una alerta ligera sin crear incidente.', 'is_terminal' => false],
            ['code' => 'INCIDENT', 'name' => 'Crear incidente', 'description' => 'Abre un incidente para respuesta operativa.', 'is_terminal' => false],
            ['code' => 'ESCALATE', 'name' => 'Escalar', 'description' => 'Activa la política de escalación.', 'is_terminal' => false],
            ['code' => 'REQUIRE_HUMAN_REVIEW', 'name' => 'Revisión humana', 'description' => 'Retiene el evento para revisión manual antes de cualquier acción.', 'is_terminal' => false],
        ];

        foreach ($outcomes as $outcome) {
            DecisionOutcome::updateOrCreate(
                ['code' => $outcome['code']],
                [
                    'name' => $outcome['name'],
                    'description' => $outcome['description'],
                    'is_terminal' => $outcome['is_terminal'],
                ],
            );
        }
    }
}
