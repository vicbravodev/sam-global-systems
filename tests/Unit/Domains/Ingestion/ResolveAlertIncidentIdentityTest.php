<?php

namespace Tests\Unit\Domains\Ingestion;

use App\Domains\Ingestion\Actions\ResolveAlertIncidentIdentity;
use PHPUnit\Framework\TestCase;

class ResolveAlertIncidentIdentityTest extends TestCase
{
    public function test_url_and_instant_identify_the_incident_with_its_state(): void
    {
        $identity = new ResolveAlertIncidentIdentity;
        $incident = [
            'incidentUrl' => 'https://cloud.samsara.com/o/1/fleet/workflows/incidents/abc/1/281/1790734561499',
            'happenedAtTime' => '2026-09-30T02:16:01Z',
            'isResolved' => true,
        ];

        $this->assertSame(
            ResolveAlertIncidentIdentity::PREFIX.sha1('url|'.$incident['incidentUrl'].'|2026-09-30T02:16:01Z').':resolved',
            $identity->key($incident),
        );
    }

    public function test_without_url_the_subject_comes_from_any_condition(): void
    {
        $identity = new ResolveAlertIncidentIdentity;
        $base = ['configurationId' => 'cfg-1', 'happenedAtTime' => '2026-10-04T10:00:00Z'];

        // El vehículo está en la segunda condición: dos unidades distintas no
        // pueden compartir huella.
        $truckA = $base + ['conditions' => [
            ['triggerId' => 1000],
            ['triggerId' => 1045, 'details' => ['tamperingDetected' => ['vehicle' => ['id' => 'A']]]],
        ]];
        $truckB = $base + ['conditions' => [
            ['triggerId' => 1000],
            ['triggerId' => 1045, 'details' => ['tamperingDetected' => ['vehicle' => ['id' => 'B']]]],
        ]];

        $this->assertSame(sha1('event|A|1000,1045|2026-10-04T10:00:00.000Z'), $identity->fingerprint($truckA));
        $this->assertNotSame($identity->fingerprint($truckA), $identity->fingerprint($truckB));
    }

    public function test_driver_is_the_subject_when_no_vehicle_is_present(): void
    {
        $incident = [
            'configurationId' => 'cfg-1',
            'happenedAtTime' => '2026-10-04T10:00:00Z',
            'conditions' => [['details' => ['panicButton' => ['driver' => ['id' => 'D1']]]]],
        ];

        $this->assertSame(sha1('cfg|cfg-1|2026-10-04T10:00:00Z|D1'), (new ResolveAlertIncidentIdentity)->fingerprint($incident));
    }

    public function test_one_press_seen_by_several_alert_configurations_shares_its_fingerprint(): void
    {
        // Caso real (prod 2026-10-04): un solo botón de pánico disparó cuatro
        // alertas de Samsara con trigger 1034. Cada una trae su propia
        // configurationId e incidentUrl, pero es el mismo pánico.
        $identity = new ResolveAlertIncidentIdentity;
        $press = fn (string $configurationId): array => [
            'configurationId' => $configurationId,
            'incidentUrl' => 'https://cloud.samsara.com/o/4006685/fleet/workflows/incidents/'.$configurationId.'/1/281474993029446/1791137900162',
            'happenedAtTime' => '2026-10-04T18:18:20Z',
            'conditions' => [['triggerId' => 1034, 'details' => ['panicButton' => ['vehicle' => ['id' => '281474993029446']]]]],
        ];

        $this->assertSame($identity->fingerprint($press('cde43156')), $identity->fingerprint($press('a0818d04')));
        $this->assertSame($identity->key($press('cde43156')), $identity->key($press('5fc69c23')));
        $this->assertSame('event', $identity->scope($press('cde43156')));
    }

    public function test_the_same_instant_is_one_press_whatever_its_timestamp_format(): void
    {
        $identity = new ResolveAlertIncidentIdentity;
        $press = fn (string $happenedAt): array => [
            'happenedAtTime' => $happenedAt,
            'conditions' => [['triggerId' => 1034, 'details' => ['panicButton' => ['vehicle' => ['id' => 'V1']]]]],
        ];

        $this->assertNotNull($identity->fingerprint($press('2026-10-04T18:18:20Z')), 'unidad + disparador + instante bastan, sin URL ni configuración');
        $this->assertSame($identity->fingerprint($press('2026-10-04T18:18:20Z')), $identity->fingerprint($press('2026-10-04T18:18:20.000Z')));
        $this->assertNotSame($identity->fingerprint($press('2026-10-04T18:18:20Z')), $identity->fingerprint($press('2026-10-04T18:18:21Z')));
    }

    public function test_different_triggers_on_the_same_unit_and_instant_are_different_incidents(): void
    {
        $identity = new ResolveAlertIncidentIdentity;
        $alert = fn (int $triggerId): array => [
            'configurationId' => 'cfg-'.$triggerId,
            'happenedAtTime' => '2026-10-04T18:18:20Z',
            'conditions' => [['triggerId' => $triggerId, 'details' => ['any' => ['vehicle' => ['id' => 'V1']]]]],
        ];

        // Un pánico y una manipulación a la vez no pueden fusionarse: se perdería el pánico.
        $this->assertNotSame($identity->fingerprint($alert(1034)), $identity->fingerprint($alert(1045)));
    }

    public function test_without_a_trigger_the_alert_configuration_stays_in_the_identity(): void
    {
        $identity = new ResolveAlertIncidentIdentity;
        $alert = fn (string $configurationId): array => [
            'configurationId' => $configurationId,
            'incidentUrl' => 'https://cloud.samsara.com/o/1/fleet/workflows/incidents/'.$configurationId.'/1/V1/1791137900162',
            'happenedAtTime' => '2026-10-04T18:18:20Z',
            'conditions' => [['description' => 'Camera Obstructed', 'details' => ['any' => ['vehicle' => ['id' => 'V1']]]]],
        ];

        // Sin triggerId no se sabe qué disparó cada alerta: no se fusionan.
        $this->assertNotSame($identity->fingerprint($alert('cfg-a')), $identity->fingerprint($alert('cfg-b')));
        $this->assertSame('alert', $identity->scope($alert('cfg-a')));
    }

    public function test_incident_without_identity_fields_has_no_key(): void
    {
        $this->assertNull((new ResolveAlertIncidentIdentity)->key(['conditions' => []]));
        $this->assertNull((new ResolveAlertIncidentIdentity)->scope(['conditions' => []]));
    }
}
