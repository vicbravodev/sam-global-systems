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

        $this->assertSame(sha1('cfg|cfg-1|2026-10-04T10:00:00Z|A'), $identity->fingerprint($truckA));
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

    public function test_incident_without_identity_fields_has_no_key(): void
    {
        $this->assertNull((new ResolveAlertIncidentIdentity)->key(['conditions' => []]));
    }
}
