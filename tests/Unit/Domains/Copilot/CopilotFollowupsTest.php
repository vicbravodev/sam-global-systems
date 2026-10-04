<?php

namespace Tests\Unit\Domains\Copilot;

use App\Domains\Copilot\Support\CopilotFollowups;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A chip is sent as the user's own message: only questions in the user's
 * voice survive, never offers of the assistant, repeats or questions
 * already asked.
 */
class CopilotFollowupsTest extends TestCase
{
    /**
     * Taken from real answers stored in dev.
     *
     * @return array<string, array{string}>
     */
    public static function offers(): array
    {
        return [
            'quieres que' => ['¿Quieres que revise el incidente abierto de la T-77 a detalle?'],
            'quieres el' => ['¿Quieres el último material de cámara asociado a distracción?'],
            'te comparo' => ['¿Te comparo esta unidad contra el resto por exceso de velocidad?'],
            'te muestro' => ['¿Te muestro tiempos de atención de cada pánico de hoy?'],
            'reviso' => ['¿Reviso si estos pánicos fueron pruebas repetidas o una activación real?'],
            'busco' => ['¿Busco desde cuándo quedó sin señal?'],
            'abro' => ['¿Abro el detalle del incidente crítico vigente?'],
            'revisamos' => ['¿Revisamos ubicación actual y tiempo desde la última señal?'],
            'te separo' => ['¿Te separo los pánicos por hora exacta?'],
            'si quieres' => ['Si quieres, te detallo cada incidente'],
            'puedo' => ['¿Puedo compararla con la flota?'],
            'without accents nor marks' => ['quieres ver la ruta de hoy'],
        ];
    }

    #[DataProvider('offers')]
    public function test_assistant_offers_are_dropped(string $offer): void
    {
        $this->assertTrue(CopilotFollowups::isOffer($offer));
        $this->assertSame(['kept' => [], 'dropped' => 1], CopilotFollowups::clean([$offer]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function userQuestions(): array
    {
        return [
            'where' => ['¿Dónde está la T-77 ahora?'],
            'imperative' => ['Muéstrame los pánicos de ayer'],
            'compare' => ['Compara la T-0524 con el resto de la flota'],
            'which unit' => ['¿Qué unidad tuvo más excesos de velocidad hoy?'],
            'how much' => ['¿Cuánto combustible gastó la T-0524 esta semana?'],
            'starts with los' => ['¿Los remolques siguen en el patio?'],
            'starts with te- word' => ['¿Tenemos incidentes sin asignar?'],
        ];
    }

    #[DataProvider('userQuestions')]
    public function test_questions_in_the_user_voice_are_kept(string $question): void
    {
        $this->assertFalse(CopilotFollowups::isOffer($question));
        $this->assertSame(['kept' => [$question], 'dropped' => 0], CopilotFollowups::clean([$question]));
    }

    public function test_drops_questions_already_asked_and_repeats_ignoring_case_and_accents(): void
    {
        $result = CopilotFollowups::clean(
            ['¿Dónde está la T-77?', 'donde esta la t-77', '¿Qué pasó ayer?', '¿Que paso ayer'],
            asked: ['¿DÓNDE ESTÁ LA T-77?'],
        );

        $this->assertSame(['kept' => ['¿Qué pasó ayer?'], 'dropped' => 3], $result);
    }

    public function test_keeps_at_most_three_trims_and_cuts_to_eighty_chars(): void
    {
        $long = '¿'.str_repeat('a', 100).'?';

        $result = CopilotFollowups::clean(['  ¿Uno?  ', '', 7, $long, '¿Tres?', '¿Cuatro?']);

        $this->assertSame(['¿Uno?', mb_substr($long, 0, 80), '¿Tres?'], $result['kept']);
        $this->assertSame(1, $result['dropped']);
    }
}
