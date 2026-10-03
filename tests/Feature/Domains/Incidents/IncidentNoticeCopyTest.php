<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentNoticeCopy;
use App\Domains\Notifications\Support\SmsText;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Lo que SAM le dice a la gente de un incidente: español de México claro,
 * sin jerga, que cabe en un SMS y que se entiende al oírlo.
 */
class IncidentNoticeCopyTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    /** Las instrucciones más largas que AppendReplyInstructions agrega (token de 4). */
    private const REPLY_INSTRUCTIONS = "\nResponde SI-W4K9 (lo atiendo), NO-W4K9 (falsa alarma) o ESC-W4K9 (pedir apoyo)";

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = User::factory()->create()->currentTeam;
    }

    private function panic(?string $unit = 'T-77 JC PZ4388A', ?string $driver = 'JESUS IVAN NOLASCO GARCIA'): Incident
    {
        $asset = $unit !== null ? Asset::factory()->create(['team_id' => $this->team->id, 'name' => $unit]) : null;
        $driverModel = $driver !== null ? Driver::factory()->create(['team_id' => $this->team->id, 'full_name' => $driver]) : null;

        return Incident::factory()->create([
            'team_id' => $this->team->id,
            'title' => 'Botón de pánico — '.($unit ?? 'sin unidad'),
            'asset_id' => $asset?->id,
            'driver_id' => $driverModel?->id,
            'opened_at' => now()->subMinutes(12),
        ]);
    }

    public function test_a_new_panic_says_what_where_and_who(): void
    {
        $copy = IncidentNoticeCopy::created($this->panic());

        $this->assertSame('Botón de pánico en la unidad T-77 JC PZ4388A', $copy['subject']);
        $this->assertSame('SAM: Botón de pánico en la unidad T-77, conductor Jesus Nolasco.', $copy['body']);
        $this->assertSame(
            'Hola, te llama SAM. Hay una alerta de botón de pánico en la unidad T 77, que maneja Jesus Nolasco. Por favor revísala en SAM lo antes posible.',
            $copy['spoken'],
        );
    }

    public function test_an_unattended_incident_says_how_long_it_has_waited(): void
    {
        Carbon::setTestNow(now());
        $copy = IncidentNoticeCopy::unattended($this->panic());

        $this->assertSame('SAM: Botón de pánico en T-77 lleva 12 minutos sin que nadie lo atienda.', $copy['body']);
        $this->assertStringContainsString('lleva 12 minutos sin que nadie la atienda', $copy['spoken']);

        $justNow = $this->panic('T-5 AB123', null);
        $justNow->forceFill(['opened_at' => now()->subSeconds(20)])->save();
        $this->assertStringContainsString('lleva 1 minuto sin', IncidentNoticeCopy::unattended($justNow)['body']);
    }

    public function test_no_notice_uses_jargon(): void
    {
        $incident = $this->panic();

        foreach ($this->allCopies($incident) as $name => $copy) {
            foreach ($copy as $field => $text) {
                foreach (['SLA', 'acknowledg', 'on-call', 'on call', 'ACK', 'escalación', 'escalad'] as $jargon) {
                    $this->assertStringNotContainsStringIgnoringCase($jargon, $text, "{$name}.{$field} usa «{$jargon}»");
                }
            }
        }
    }

    public function test_every_sms_fits_one_message_with_the_reply_instructions(): void
    {
        // El caso largo: unidad con número económico largo y 3 dígitos de minutos.
        $incident = $this->panic('TR-7445 JC PJ3951C', 'MARIA GUADALUPE HERNANDEZ RODRIGUEZ');
        $incident->forceFill(['opened_at' => now()->subMinutes(125)])->save();

        foreach ($this->allCopies($incident) as $name => $copy) {
            $sms = SmsText::gsm7($copy['body']).self::REPLY_INSTRUCTIONS;

            $this->assertLessThanOrEqual(160, mb_strlen($sms), "{$name}: «{$sms}» no cabe en un SMS");
        }
    }

    public function test_spoken_text_skips_plates_and_reads_the_economic_number(): void
    {
        $incident = $this->panic();

        foreach ($this->allCopies($incident) as $name => $copy) {
            $this->assertStringStartsWith('Hola, te llama SAM.', $copy['spoken'], $name);
            $this->assertStringNotContainsString('PZ4388A', $copy['spoken'], $name);
            $this->assertStringNotContainsString('T-77', $copy['spoken'], $name);
        }
    }

    public function test_without_unit_or_driver_the_text_still_reads_well(): void
    {
        $incident = $this->panic(null, null);
        $incident->forceFill(['title' => 'Botón de pánico'])->save();

        $copy = IncidentNoticeCopy::created($incident);

        $this->assertSame('Botón de pánico', $copy['subject']);
        $this->assertSame('SAM: Botón de pánico.', $copy['body']);
        $this->assertSame('Hola, te llama SAM. Hay una alerta de botón de pánico. Por favor revísala en SAM lo antes posible.', $copy['spoken']);
    }

    public function test_unit_and_driver_names_are_shortened_sensibly(): void
    {
        $this->assertSame('Ana Ruiz', IncidentNoticeCopy::driverShortName($this->panic('T-1 A', 'ANA RUIZ')));
        $this->assertSame('Ana Ruiz', IncidentNoticeCopy::driverShortName($this->panic('T-1 A', 'ANA RUIZ LOPEZ')));

        // Sin número en el primer pedazo, la unidad va completa.
        $this->assertSame('Unidad Norte', IncidentNoticeCopy::shortUnit($this->panic('Unidad Norte', null)));
        $this->assertSame('T 591', IncidentNoticeCopy::spokenUnit($this->panic('T-591 SC WM6349C', null)));
    }

    public function test_the_text_never_reads_another_tenants_unit_or_driver(): void
    {
        $teamB = User::factory()->create()->currentTeam;
        $foreignAsset = Asset::factory()->create(['team_id' => $teamB->id, 'name' => 'B-99 AJENA']);
        $foreignDriver = Driver::factory()->create(['team_id' => $teamB->id, 'full_name' => 'PEDRO AJENO PEREZ']);

        // Un incidente con ids ajenos (dato corrupto): el texto no los lee.
        $incident = Incident::factory()->create([
            'team_id' => $this->team->id,
            'title' => 'Botón de pánico — B-99 AJENA',
            'asset_id' => $foreignAsset->id,
            'driver_id' => $foreignDriver->id,
        ]);

        $copy = $this->assertNoTenantLeak($this->team, fn () => IncidentNoticeCopy::created(Incident::query()->findOrFail($incident->id)));

        $this->assertStringNotContainsString('Pedro', $copy['body']);
        $this->assertStringNotContainsString('unidad B-99', $copy['body']);
    }

    /**
     * @return array<string, array{subject: string, body: string, spoken: string}>
     */
    private function allCopies(Incident $incident): array
    {
        return [
            'created' => IncidentNoticeCopy::created($incident),
            'onCallAssigned' => IncidentNoticeCopy::onCallAssigned($incident),
            'unattended' => IncidentNoticeCopy::unattended($incident),
            'emergencyConfirmed' => IncidentNoticeCopy::emergencyConfirmed($incident),
            'verificationUnanswered' => IncidentNoticeCopy::verificationUnanswered($incident),
            'verificationUnavailable' => IncidentNoticeCopy::verificationUnavailable($incident),
            'becameCritical' => IncidentNoticeCopy::becameCritical($incident),
        ];
    }
}
