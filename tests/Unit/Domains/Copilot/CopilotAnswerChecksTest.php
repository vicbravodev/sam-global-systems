<?php

namespace Tests\Unit\Domains\Copilot;

use App\Domains\Copilot\Support\CopilotAnswerChecks;
use PHPUnit\Framework\TestCase;

/**
 * The checks `copilot:eval` grades every answer with, fed with the clumsy
 * answers stored in dev before the prompt rewrite and with good ones.
 */
class CopilotAnswerChecksTest extends TestCase
{
    public function test_a_good_answer_passes_every_check(): void
    {
        $checks = CopilotAnswerChecks::run(
            'La **T-0524** va en ruta por Mesquite, TX, a **87 km/h**; su última señal fue hoy a las 20:44.',
            'Hoy van **2** botones de pánico, ambos en la **T-77**.',
            ['¿Cuánto combustible le queda a la T-0524?', 'Muéstrame su ruta de hoy'],
            ['Botones de pánico de hoy', '¿Dónde está la T-0524?'],
        );

        $this->assertSame([], array_filter($checks), json_encode(array_filter($checks), JSON_UNESCAPED_UNICODE));
    }

    public function test_flags_raw_utc_and_iso_times(): void
    {
        $this->assertNotNull(CopilotAnswerChecks::localTimes('su última señal fue a las **02:44:56 UTC**'));
        $this->assertNotNull(CopilotAnswerChecks::localTimes('capturado a las **2026-09-30T18:39:11+00:00**'));
        $this->assertNull(CopilotAnswerChecks::localTimes('capturado ayer a las 12:39'));
    }

    public function test_flags_preambles_and_the_fixed_closing_formula(): void
    {
        $this->assertNotNull(CopilotAnswerChecks::noPreamble('Claro, aquí está el reporte.'));
        $this->assertNotNull(CopilotAnswerChecks::noPreamble('**Voy a** revisar la unidad.'));
        $this->assertNull(CopilotAnswerChecks::noPreamble('La T-77 está detenida.'));

        $this->assertNotNull(CopilotAnswerChecks::noFormula('Va en ruta. Siguiente paso recomendado: asignar el incidente.'));
        $this->assertNotNull(CopilotAnswerChecks::noFormula("Va en ruta.\nRiesgo: incidente vencido."));
        $this->assertNull(CopilotAnswerChecks::noFormula('Conviene asignar hoy el incidente vencido.'));
    }

    public function test_flags_answers_longer_than_the_turn_allows(): void
    {
        $this->assertSame('501 caracteres (máx 500)', CopilotAnswerChecks::length(str_repeat('a', 501), 500));
        $this->assertNull(CopilotAnswerChecks::length(str_repeat('a', 500), 500));
    }

    public function test_flags_an_answer_that_restates_the_previous_one(): void
    {
        $previous = 'La unidad T-0524 está activa y en ruta por Henderson, LA, a 103 km/h. Trae 1 incidente abierto crítico por botón de pánico, sin asignación y con SLA vencido.';
        $repeat = 'La unidad T-0524 está activa y en ruta por Henderson, LA, a 103 km/h. Trae 1 incidente abierto crítico por botón de pánico, sin asignación y con SLA vencido. Su tanque está en 58% y cargó combustible dos veces.';
        $fresh = 'Su tanque está en 58% y cargó combustible dos veces esta semana, sin caídas bruscas.';

        $this->assertStringStartsWith('repite 67%', (string) CopilotAnswerChecks::noRepetition($repeat, $previous));
        $this->assertNull(CopilotAnswerChecks::noRepetition($fresh, $previous));
        $this->assertNull(CopilotAnswerChecks::noRepetition($repeat, null));
    }

    public function test_flags_offer_chips_and_chips_already_asked(): void
    {
        $this->assertSame(
            'pill como ofrecimiento: "¿Quieres que revise el incidente?"',
            CopilotAnswerChecks::userVoice(['¿Dónde está la T-77?', '¿Quieres que revise el incidente?']),
        );
        $this->assertNotNull(CopilotAnswerChecks::newQuestions(['¿Dónde está la T-77?'], ['¿dónde está la T-77?']));
        $this->assertNull(CopilotAnswerChecks::newQuestions(['¿Y su combustible?'], ['¿Dónde está la T-77?']));
    }

    public function test_flags_empty_answers_and_missing_chips(): void
    {
        $checks = CopilotAnswerChecks::run('No pude completar la respuesta.', null, [], []);

        $this->assertSame('respuesta vacía', $checks['respondio']);
        $this->assertSame('sin preguntas de seguimiento', $checks['pills_presentes']);
    }
}
