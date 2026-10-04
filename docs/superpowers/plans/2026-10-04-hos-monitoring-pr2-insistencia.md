# Monitoreo HOS — PR 2: insistencia al chofer — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sobre los episodios HOS que ya detecta el PR 1, SAM le insiste al chofer por una escalera de canales (app de Samsara → app + WhatsApp → llamada con la voz de SAM) y, si no corrige o Samsara marca infracción, abre un incidente por el pipeline existente; si el chofer corrige antes de que alguien lo tome, el incidente se cierra solo.

**Architecture:** Al final de cada `SyncHosClocksJob` (después de `ProcessHosReadings`) corre `AdvanceHosEpisodes`: por episodio abierto, un planificador puro (`HosLadderPlanner`) decide qué toca este minuto y la acción lo ejecuta — `SendHosNudge` (notificación con `event_key` `hos:{episodio}:{escalón}` por el dominio Notifications, con canal nuevo `samsara_driver_app`, destinatario `driver` y fuente `hos_episode`) o `RaiseHosIncident` (evento interno `internal_monitor` con clave `hos:{episodio}` → normalización → evaluación por regla sin IA → regla por defecto `hos-incident` → incidente). `LinkHosEpisodeIncident` guarda el incidente en el episodio (mismo team) y `SettleHosIncident` lo cierra o anota cuando `ProcessHosReadings` ve la corrección.

**Tech Stack:** Laravel 13 · PHP 8.5 · PostgreSQL 18 (SQLite en tests) · PHPUnit 13 · Http::fake (Samsara) · Mockery sobre `TwilioMessenger`/`TwilioVoiceCaller` (Twilio) · React 19/TS (sólo etiquetas).

**Spec:** `docs/superpowers/specs/2026-10-04-hos-monitoring-design.md` (§3.9, §3.10, §3.11, §5, §6, §7 — PR 2). Plan anterior: `docs/superpowers/plans/2026-10-04-hos-monitoring-pr1-observacion.md`.

## Global Constraints

- Tenant = `Team`. Patrones de `TenantContext` (`app/CLAUDE.md`): job que recibe un modelo = trabajar dentro de `TenantContext::for($integration->team_id, ...)`; actions que reciben un `teamId`/modelo = `TenantContext::for`; nunca `set()` fuera de un job. Toda query con `where('team_id', ...)` explícito además del scope.
- Ids de Samsara son únicos platform-wide: la dirección de la app del chofer lleva el `integration_id` y el driver la valida contra el **team de la entrega** antes de usarla.
- **Escalera por episodio (decisión del usuario, ya en `config/hos.php` → `hos.defaults.ladder`):** escalón 0 a los 0 min `[samsara_driver_app]` → escalón 1 a +5 `[samsara_driver_app, whatsapp]` → escalón 2 a +10 `[voice]` → escalón 3 a +15 `escalate: incident` (incidente al monitorista en turno). **Se envía aunque vaya manejando** (decisión del cliente: soporte de celular).
- **Avisos previos 30/15/0 min** antes de un límite = informativos; llegar al límite (0) arranca la escalera completa. `cycle_limit`: un aviso informativo por umbral (5 h, 1 h), sin escalera. `rest_complete`: avisos a +15 y +30 min de abierto, luego expira (35 min) sin incidente.
- **La escalera se PAUSA** mientras el chofer no maneja/trabaja (cumplió) y se reanuda si vuelve a manejar sin que el reloj se haya restablecido.
- **Incidente sólo** si la escalera al chofer se agota o hay violación. Por el pipeline existente (como `app/Domains/Assets/Jobs/DetectUnauthorizedStopJob.php`): evento crudo `EventSourceType::InternalMonitor`, tipos **propios y nuevos** `hos_limit_exceeded` (los relojes de Samsara marcan infracción de un chofer inscrito) y `hos_unattended` (escalera agotada), ambos `compliance`/`high`. **`hos_violation` (evento que ya manda Samsara por webhook, mapeo en `NormalizationSeeder.php:285`) no se toca para ningún tenant** (decisión del controlador); regla por defecto en `ApplyDefaultTenantConfig` → outcome `INCIDENT` con `stop_processing`; **no** se agrega a `ai.skip_evaluation_categories` (invariante 6); migración que lo lleva a tenants existentes.
- El incidente **se cierra solo** si el chofer corrige antes de que alguien lo atienda (sin acuse ni toma); si ya alguien lo tomó, sólo se agrega una entrada en la línea de tiempo.
- **Copy:** español de México, de tú, voz de SAM, SMS ≤ 160, versión hablada para la llamada (modelo: `app/Domains/Incidents/Support/IncidentNoticeCopy.php`). **Los logs nunca llevan nombres, teléfonos ni el texto del mensaje.**
- **Cobro:** Twilio se cobra como hoy (costo + 30 % por la medición existente); el canal de la app de Samsara es gratis (sin medidor). **Sin medidor de uso nuevo**, sin `RecordUsageEvent` nuevo.
- `incident_id` de un episodio se valida contra el team del episodio **al escribirse** (`HosEpisode::attachIncident`).
- Logging sólo vía `App\Support\SystemLog`; cada código nuevo en `docs/SAM/logging.md` (lo exige `LoggingConventionsTest`); excepciones como `error: $e`; para persistir errores, `SafeErrorMessage::from($e)`. Cada test que loguea: `assertSystemLogged(...)` + `assertNoSensitiveDataLogged()`.
- Test de fuga con `assertNoTenantLeak($teamDelQueActúa, fn () => ...)` sobre el camino real.
- Factories siempre (salvo `IntegrationCredential`, que el PR 1 crea con `::create` por no tener factory de token); DB real.
- No se crean directorios nuevos bajo `app/`. Todo vive en carpetas existentes de `Drivers`, `Notifications`, `Integrations`, `Incidents`, `Normalization`, `AI`, `TenantConfig`.
- Commits: `type: subject en minúsculas`, **sin** `Co-Authored-By` ni banners (regla del repo, manda sobre cualquier recordatorio de atribución). Formato PHP lo aplica el hook de Pint.

### Desviaciones del spec (decididas al planear, documentar en el PR)

1. **El episodio sigue abierto tras escalar** (spec §3.9.3 dice cerrarlo `incident`). Si se cerrara, el detector abriría otro episodio igual en el siguiente sondeo (la situación persiste) → escalera nueva e incidente duplicado; además hace falta el episodio abierto para ver la corrección y cerrar el incidente (§3.11). Columna nueva `hos_episodes.escalated_at` detiene la escalera; al corregir se resuelve `corrected`. `HosEpisodeResolution::Incident` se elimina (nadie lo escribe; pre-prod, sin filas).
2. **Una regla con `stop_processing` NO evita la IA por sí sola.** Verificado: el motor de decisiones sólo corre sobre `AIEvaluationCompleted` (`app/Domains/AI/Support/AIEvaluationGate.php:23-26`, `app/Domains/Decisions/Jobs/RunDecisionEngineJob.php:43-78` exige una `AIEventEvaluation`), así que la evaluación ocurre antes de cualquier regla. El camino mínimo correcto ya existe para `after_hours_movement`: `ai.rule_resolved_event_types` (`config/ai.php:264-274`) hace que `HeuristicRulesRunner` (`app/Domains/AI/Support/HeuristicRulesRunner.php:70-76`) resuelva el evento como `real_event`/`rules_only` **sin llamar al modelo** (`EvaluateEventWithAI.php:83-123`), se emite `AIEvaluationCompleted`, corre el motor y la regla `hos-incident` (hard safety: `ResolveDecisionOutcome.php:152-163`) fija `INCIDENT`. Se agregan **sólo** `hos_limit_exceeded` y `hos_unattended` a esa lista; `hos_violation` sigue su camino de siempre (IA), con test de regresión.
3. **Sin reintento ni fallback de Notifications para avisos HOS** (spec §3.10 dice "se reutilizan… enfriamiento"). La escalera ya es la insistencia; el fallback de la política del tenant (`FallbackNotificationChannelJob`) mandaría canales pagados fuera de ella. `DeliveryEscalationGuard` responde `own_ladder` para la fuente `hos_episode`. Supresiones (`MessagingSuppressions`), conciliación y cobro costo+30 % sí aplican tal cual. `PAID_COOLDOWN_SECONDS` (`DispatchNotification.php:467`) sólo actúa sobre fuentes `incident` y los escalones están ≥ 5 min aparte: no aplica.
4. **"Canal sin permiso → brinca de escalón" se generaliza**: el envío es asíncrono (`SendNotificationJob`), así que no se sabe en el momento. En el siguiente ciclo, si la notificación del escalón anterior quedó `failed`/`cancelled` (Samsara 401/403 por falta de *Write Messages*, chofer sin teléfono, número suprimido, WhatsApp fuera de ventana), el siguiente escalón se adelanta a ese minuto (`hos.nudge.channel_unavailable`).
5. **Avisos informativos** (30/15 min, ciclo, fin de pausa) salen por los canales del primer escalón de la escalera (por defecto sólo la app, gratis). **Violación**: un aviso por la app + incidente en el mismo ciclo, sin escalera.
6. **Sin horario silencioso por fuente**, no por prioridad: `SelectNotificationChannels` ignora el silencio para `hos_episode` (prioridad `high`). Usar `critical` habría inflado los contadores de críticas y pedido tokens de respuesta.
7. **La escalera corre dentro de `SyncHosClocksJob`**, no en un job aparte por minuto: usa los relojes de ese mismo minuto y el mismo candado `ShouldBeUnique`; si Samsara falla ese minuto la escalera tampoco avanza (estado desconocido: ni infracción ni corrección, igual que el PR 1).
8. **Destinatario `driver` sin tocar `ResolveRecipients`**: `buildExplicit` (`ResolveRecipients.php:43-94`) ya acepta cualquier `RecipientType` en `payload_json.recipients`; `SendHosNudge` arma el destinatario (teléfono del `Driver` + dirección de la app con la `DriverExternalReference`).
9. **El canal `samsara_driver_app` no se ofrece en la UI del tenant** (`ProvidedChannels` lo excluye): sólo sirve a choferes.
10. **La regla se lleva a tenants existentes sólo en su ruleset propio por defecto y activo**; `PACK_VERSION` se queda en 1 (la regla nueva entra por migración y el pack sigue idempotente).
11. **Eventos internos con chofer**: `NormalizeRawEvent` acepta `internal.driver_id` (sólo si el chofer es del team del evento) para que el incidente salga con chofer.
12. **Códigos de evento propios** (ruling del controlador): el monitor interno usa `hos_limit_exceeded` (infracción en los relojes de un chofer inscrito) y `hos_unattended` (escalera agotada). El `hos_violation` existente (webhook Samsara, `NormalizationSeeder.php:285`) no cambia de comportamiento para ningún tenant: no entra en `rule_resolved_event_types`, ni en la regla `hos-incident`, ni en el alias a `hos_compliance`.
13. **Deudas del PR 1 incluidas**: constante muerta `FULL_SHIFT_SECONDS`; nombres de tests del detector que decían "reset"; ventana de estado viejo 300 s → `rest_complete_expire_minutes` (35); un chofer que reconecta tras una desconexión larga no usa los relojes congelados como "antes" (cuenta desde `app_disconnected_since`).

## Review Focus

1. **Mensajes duplicados al chofer** (ciclo solapado, reintento del job, dos integraciones): la clave `hos:{episodio}:{escalón}` debe frenar el segundo envío y la clave `hos:{episodio}` el segundo evento interno. → Task 7, `test_a_step_is_never_sent_twice` y `test_at_the_limit_the_ladder_climbs_...` (re-ejecución tras escalar).
2. **Insistir a un chofer que ya se detuvo** (o con la app desconectada): la escalera debe pausarse y reanudar sólo si vuelve a manejar sin cumplir. → Task 5 `test_the_ladder_pauses_while_the_driver_is_not_working`, Task 7 `test_the_ladder_pauses_...` y `test_a_disconnected_driver_app_holds_the_ladder`.
3. **El incidente nunca abre** porque la IA se interpone o no hay regla: debe resolverse sin IA y con decisión `INCIDENT`. → Task 6 `test_hos_events_open_an_incident_without_calling_the_ai` (agente que lanza si se llama); y lo contrario: un `hos_violation` que manda Samsara sigue yendo a la IA como antes → Task 6 `test_a_samsara_hos_violation_still_goes_to_the_ai`.
4. **Cerrar un incidente que alguien ya tomó** o que comparte otro episodio abierto y escalado del mismo chofer, aunque aún no esté vinculado (dedup de `CreateIncidentFromEvent` por unidad/chofer en 30 min). → Task 8 `test_an_incident_someone_already_took_only_gets_a_timeline_entry`, `test_an_incident_shared_with_another_open_episode_stays_open` y `test_another_escalated_episode_not_yet_linked_keeps_the_incident_open`.
5. **Cruce de tenants**: dirección de la app apuntando a la integración de otro team; episodios/notificaciones de otro tenant; incidente ajeno en `incident_id`. → Task 2 `test_an_address_pointing_at_another_tenants_integration_is_never_used`, Task 1 `test_an_episode_points_only_to_an_incident_of_its_own_team`, Task 7 `test_advancing_one_tenant_never_touches_another`, Task 8 `test_settling_never_touches_another_tenants_incident`.

---

## File Structure

| Archivo | Responsabilidad |
|---|---|
| `database/migrations/2026_10_11_100000_add_escalated_at_to_hos_episodes_table.php` | marca de escalado |
| `app/Domains/Drivers/Models/HosEpisode.php` (mod) | `escalated_at`, `incident()`, `attachIncident()` |
| `app/Domains/Drivers/Enums/HosEpisodeResolution.php` (mod) | quita `Incident` |
| `app/Domains/Drivers/Support/HosSituationDetector.php` (mod) | quita `FULL_SHIFT_SECONDS` |
| `app/Domains/Drivers/Actions/ProcessHosReadings.php` (mod) | "antes" confiable + cierre del incidente al corregir |
| `app/Domains/Integrations/Contracts/{ProviderAdapter,NullProviderAdapter}.php`, `Adapters/{ProviderAdapterManager,SamsaraAdapter}.php` (mod) | `sendDriverMessage()` (`POST /v1/fleet/messages`) |
| `app/Domains/Notifications/Enums/{ChannelType,RecipientType,NotificationSourceType}.php` (mod) | `samsara_driver_app`, `driver`, `hos_episode` |
| `app/Domains/Notifications/Support/SamsaraDriverAppAddress.php` | dirección `samsara:{integration}:{chofer}` |
| `app/Domains/Notifications/Channels/SamsaraDriverAppNotificationDriver.php` | driver del canal |
| `app/Domains/Notifications/Channels/ChannelDriverRegistry.php`, `Models/NotificationRecipient.php`, `Actions/AttemptDelivery.php`, `Support/ProvidedChannels.php` (mod) | registro, dirección, canal sin medidor, no ofrecerlo al tenant |
| `app/Domains/Notifications/Actions/SelectNotificationChannels.php`, `Support/DeliveryEscalationGuard.php`, `Support/NotificationTypeLabels.php` (mod) | sin silencio, sin reintento/fallback, etiqueta |
| `database/seeders/PlatformChannelSeeder.php`, `database/factories/Domains/Notifications/NotificationChannelFactory.php` (mod), `database/migrations/2026_10_11_100100_seed_platform_samsara_driver_app_channel.php` | canal de plataforma |
| `app/Domains/Drivers/Enums/{HosNotice,HosLadderMove}.php`, `Data/HosLadderDecision.php`, `Support/{HosNoticeCopy,HosLadderPlanner}.php`, `Support/HosMonitoringConfig.php` (mod) | copy y escalera pura |
| `app/Domains/Incidents/Enums/IncidentTypeCode.php`, `Actions/CreateIncidentFromEvent.php`, `app/Domains/Automation/Support/TriggerConditionCatalog.php` (mod), `database/seeders/{IncidentTypeSeeder,NormalizationSeeder}.php` (mod) | tipo de incidente `hos_compliance`, evento `hos_unattended` |
| `config/ai.php`, `app/Domains/AI/Actions/EvaluateEventWithAI.php`, `app/Domains/Normalization/Actions/NormalizeRawEvent.php`, `app/Domains/TenantConfig/Actions/ApplyDefaultTenantConfig.php` (mod) | sin IA, chofer en evento interno, regla por defecto |
| `database/migrations/2026_10_11_100200_seed_hos_incident_catalog.php` | catálogo + regla a tenants existentes |
| `app/Domains/Drivers/Actions/{RaiseHosIncident,LinkHosEpisodeIncident,SendHosNudge,AdvanceHosEpisodes,SettleHosIncident}.php` | acciones de la escalera |
| `app/Domains/Drivers/Jobs/SyncHosClocksJob.php` (mod) | corre la escalera en cada sondeo |
| `resources/js/lib/labels.ts`, `resources/js/types/notifications.ts`, `resources/js/components/sam/notifications/{notifications-table.tsx,detail/delivery-item.tsx}` (mod), `resources/js/lib/labels.test.ts` | etiquetas/íconos |
| `docs/SAM/logging.md` (mod) | códigos nuevos |
| `tests/Feature/Domains/Drivers/Hos/HosTenantFixtures.php` + tests por task | pruebas |

---

### Task 0: Bootstrap del worktree

- [ ] **Step 1:** Invocar la skill `worktree-bootstrap` (vendor, `.env`, Wayfinder). `composer install` **real** en el worktree (con `vendor` symlinkeado los tests cargan `App\` del checkout principal y RED/GREEN miente).
- [ ] **Step 2:** Línea base: `php artisan test --compact tests/Feature/Domains/Drivers tests/Unit/Domains/Drivers tests/Feature/Domains/Notifications tests/Feature/Architecture/LoggingConventionsTest.php` → PASS.

---

### Task 1: Deudas del PR 1 y marca de escalado del episodio

**Files:**
- Create: `database/migrations/2026_10_11_100000_add_escalated_at_to_hos_episodes_table.php`
- Modify: `app/Domains/Drivers/Models/HosEpisode.php`, `app/Domains/Drivers/Enums/HosEpisodeResolution.php`, `app/Domains/Drivers/Support/HosSituationDetector.php`, `app/Domains/Drivers/Actions/ProcessHosReadings.php`
- Test: `tests/Feature/Domains/Drivers/Hos/ProcessHosReadingsTest.php`, `tests/Feature/Domains/Drivers/Hos/HosModelsTest.php`, `tests/Unit/Domains/Drivers/HosSituationDetectorTest.php` (sólo renombres)

**Interfaces:**
- Produces: `HosEpisode::$escalated_at` (`?Carbon`), `HosEpisode::incident(): BelongsTo<Incident>`, `HosEpisode::attachIncident(Incident $incident): void` (lanza `LogicException` si el team difiere). `HosEpisodeResolution` = `corrected|expired|unenrolled`. `ProcessHosReadings` usa como "antes" el estado cuyo último dato real (`app_disconnected_since ?? observed_at`) tiene ≤ `restCompleteExpireSeconds()`.

- [ ] **Step 1: Tests que fallan.** En `ProcessHosReadingsTest.php` renombrar `test_it_resolves_when_the_clock_resets` → `test_it_resolves_when_the_clock_goes_back_above_the_threshold` y agregar al final de la clase:

```php
    public function test_a_break_served_during_a_short_provider_outage_still_reads_as_rest_complete(): void
    {
        $this->process($this->enrollment($this->reading('driving', break: 1500)));
        $this->process($this->enrollment($this->reading('offDuty', break: 1500)), '2026-10-04 12:05:00');
        // Samsara no respondió 15 min; al volver la pausa ya está cumplida.
        $this->process($this->enrollment($this->reading('offDuty', break: 28800)), '2026-10-04 12:20:00');

        $this->assertSame(1, HosEpisode::withoutGlobalScopes()->open()->where('situation', HosSituation::RestComplete)->count());
    }

    public function test_a_driver_reconnecting_after_a_long_disconnect_does_not_read_frozen_clocks_as_a_pause(): void
    {
        $this->process($this->enrollment($this->reading('offDuty', break: 600)), '2026-10-04 10:00:00');
        $this->process($this->enrollment($this->reading(null)), '2026-10-04 10:01:00');
        // observed_at se sigue refrescando con relojes congelados mientras la app está apagada.
        $this->process($this->enrollment($this->reading(null)), '2026-10-04 10:59:00');
        $this->process($this->enrollment($this->reading('offDuty', break: 28800)), '2026-10-04 11:00:00');

        $this->assertSame(0, HosEpisode::withoutGlobalScopes()->where('situation', HosSituation::RestComplete)->count());
    }

    public function test_a_short_disconnect_still_uses_the_last_real_clocks(): void
    {
        $this->process($this->enrollment($this->reading('offDuty', break: 600)), '2026-10-04 12:00:00');
        $this->process($this->enrollment($this->reading(null)), '2026-10-04 12:01:00');
        $this->process($this->enrollment($this->reading('offDuty', break: 28800)), '2026-10-04 12:10:00');

        $this->assertSame(1, HosEpisode::withoutGlobalScopes()->open()->where('situation', HosSituation::RestComplete)->count());
    }
```

En `HosModelsTest.php` agregar `use App\Domains\Incidents\Models\Incident;` y `use LogicException;`, y:

```php
    public function test_an_episode_points_only_to_an_incident_of_its_own_team(): void
    {
        $driver = Driver::factory()->create();
        $episode = HosEpisode::factory()->create([
            'team_id' => $driver->team_id,
            'driver_id' => $driver->id,
            'escalated_at' => '2026-10-04 12:15:00',
        ]);
        $mine = Incident::factory()->create(['team_id' => $driver->team_id]);

        $episode->attachIncident($mine);

        TenantContext::for($driver->team_id, function () use ($episode, $mine): void {
            $fresh = $episode->fresh();
            $this->assertSame($mine->id, $fresh->incident_id);
            $this->assertSame('2026-10-04 12:15:00', $fresh->escalated_at->format('Y-m-d H:i:s'));
            $this->assertTrue($fresh->incident->is($mine));
        });

        $this->expectException(LogicException::class);
        $episode->attachIncident(Incident::factory()->create());
    }
```

En `tests/Unit/Domains/Drivers/HosSituationDetectorTest.php` renombrar (sin tocar el cuerpo): `test_break_due_resolves_only_when_the_break_clock_resets` → `test_break_due_stays_open_on_a_short_stop_and_resolves_above_the_threshold`; `test_cycle_limit_opens_and_resolves_on_reset` → `test_cycle_limit_opens_and_resolves_once_back_above_its_margin`.

- [ ] **Step 2:** Run `php artisan test --compact --filter='ProcessHosReadingsTest|HosModelsTest|HosSituationDetectorTest'` → FAIL (3 tests de lectura previa + `attachIncident` no existe / columna `escalated_at`).

- [ ] **Step 3: Migración** `database/migrations/2026_10_11_100000_add_escalated_at_to_hos_episodes_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Monitoreo HOS PR 2: cuándo la escalera de un episodio llegó al
     * incidente. El episodio sigue abierto tras escalar (si se cerrara, el
     * detector abriría otro igual en el siguiente sondeo); esta marca detiene
     * la escalera y le dice a la corrección que hay un incidente que atender.
     */
    public function up(): void
    {
        Schema::table('hos_episodes', function (Blueprint $table) {
            $table->timestamp('escalated_at')->nullable()->after('next_nudge_at');
        });
    }

    public function down(): void
    {
        Schema::table('hos_episodes', function (Blueprint $table) {
            $table->dropColumn('escalated_at');
        });
    }
};
```

- [ ] **Step 4: Modelo y enum.** En `HosEpisode.php`: agregar `use App\Domains\Incidents\Models\Incident;` y `use LogicException;`; en `$fillable` agregar `'escalated_at',` después de `'next_nudge_at',`; en `casts()` agregar `'escalated_at' => 'datetime',`; y después de `asset()`:

```php
    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * Stores the incident this episode escalated into. Never one of another
     * tenant: the incident is found through ids, so the team is re-checked
     * at the only place that writes the column.
     */
    public function attachIncident(Incident $incident): void
    {
        if ($incident->team_id !== $this->team_id) {
            throw new LogicException('An HOS episode can only point to an incident of its own team.');
        }

        $this->forceFill(['incident_id' => $incident->id])->save();
    }
```

Y actualizar el docblock de la clase:

```php
/**
 * One HOS situation of one driver, from detection until it is corrected,
 * expires or the driver leaves the monitored set. An episode whose reminder
 * ladder escalated keeps open (`escalated_at`) until the driver corrects.
 */
```

En `HosEpisodeResolution.php` borrar la línea `case Incident = 'incident';`.

En `HosSituationDetector.php` borrar `public const int FULL_SHIFT_SECONDS = 50400;` y su línea en blanco.

- [ ] **Step 5: "Antes" confiable en `ProcessHosReadings.php`.** Borrar la constante `STALE_STATE_SECONDS` con su docblock. Reemplazar:

```php
                $previous = $state?->observed_at !== null && $state->observed_at->gte($now->toImmutable()->subSeconds(self::STALE_STATE_SECONDS))
                    ? $state->toReading($reading->externalDriverId, $reading->externalVehicleId)
                    : null;
```

por:

```php
                $previous = $this->previousReading($state, $reading, $config, $now);
```

y agregar el método después de `execute()`:

```php
    /**
     * The stored clocks are only a usable "before" of a transition while
     * they are recent. Up to the rest-complete window (default 35 min) the
     * last real reading is trustworthy: a break served during a short
     * Samsara outage must not be missed. Past it, the natural reset of the
     * clocks after hours away would read as a pause just served (false
     * rest_complete). A disconnected app keeps refreshing `observed_at` with
     * frozen clocks, so its age counts from `app_disconnected_since`, the
     * last moment the clocks were real.
     */
    private function previousReading(?HosDriverState $state, HosClockReading $reading, HosMonitoringConfig $config, CarbonInterface $now): ?HosClockReading
    {
        if ($state === null || $state->observed_at === null) {
            return null;
        }

        $lastRealAt = $state->app_disconnected_since ?? $state->observed_at;

        if ($lastRealAt->lt($now->toImmutable()->subSeconds($config->restCompleteExpireSeconds()))) {
            return null;
        }

        return $state->toReading($reading->externalDriverId, $reading->externalVehicleId);
    }
```

- [ ] **Step 6:** Run `php artisan test --compact --filter='ProcessHosReadingsTest|HosModelsTest|HosSituationDetectorTest'` → PASS.
- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_10_11_100000_add_escalated_at_to_hos_episodes_table.php app/Domains/Drivers tests/Feature/Domains/Drivers/Hos tests/Unit/Domains/Drivers/HosSituationDetectorTest.php
git commit -m "fix: lectura previa hos confiable y marca de escalado del episodio"
```

---

### Task 2: Canal `samsara_driver_app` y destinatario `driver`

**Files:**
- Modify: `app/Domains/Integrations/Contracts/ProviderAdapter.php`, `app/Domains/Integrations/Contracts/NullProviderAdapter.php`, `app/Domains/Integrations/Adapters/ProviderAdapterManager.php`, `app/Domains/Integrations/Adapters/SamsaraAdapter.php`
- Modify: `app/Domains/Notifications/Enums/ChannelType.php`, `app/Domains/Notifications/Enums/RecipientType.php`, `app/Domains/Notifications/Models/NotificationRecipient.php`, `app/Domains/Notifications/Channels/ChannelDriverRegistry.php`, `app/Domains/Notifications/Actions/AttemptDelivery.php`, `app/Domains/Notifications/Support/ProvidedChannels.php`
- Create: `app/Domains/Notifications/Support/SamsaraDriverAppAddress.php`, `app/Domains/Notifications/Channels/SamsaraDriverAppNotificationDriver.php`, `database/migrations/2026_10_11_100100_seed_platform_samsara_driver_app_channel.php`
- Modify: `database/seeders/PlatformChannelSeeder.php`, `database/factories/Domains/Notifications/NotificationChannelFactory.php`, `docs/SAM/logging.md`
- Modify (front): `resources/js/lib/labels.ts`, `resources/js/types/notifications.ts`, `resources/js/components/sam/notifications/notifications-table.tsx`, `resources/js/components/sam/notifications/detail/delivery-item.tsx`; Create: `resources/js/lib/labels.test.ts`
- Test: Create `tests/Feature/Domains/Drivers/Hos/HosTenantFixtures.php`, `tests/Feature/Domains/Notifications/SamsaraDriverAppChannelTest.php`; Modify `tests/Unit/Domains/Notifications/ChannelTypeUsageMeterCodeTest.php`, `tests/Feature/Domains/Notifications/PlatformChannelSeederTest.php`

**Interfaces:**
- Produces:
  - `ProviderAdapter::sendDriverMessage(TenantIntegration $integration, string $externalDriverId, string $text): void` — `POST /v1/fleet/messages` `{driverIds: [int], text ≤ 2500}`; lanza `ProviderUnauthorized` (sin token, 401/403), `ProviderRateLimited` (429), `ProviderUnavailable` (5xx/red), `ProviderRequestFailedException` (otro no-2xx o id no numérico).
  - `ChannelType::SamsaraDriverApp` (`'samsara_driver_app'`, label `App de Samsara`); `ChannelType::usageMeterCode(): ?string` (`null` para la app).
  - `RecipientType::Driver` (`'driver'`).
  - `NotificationRecipient::SAMSARA_APP_ADDRESS_KEY = 'samsara_app_address'` (clave en `metadata_json`); `addressForChannel(SamsaraDriverApp)` = esa dirección sólo para destinatarios `driver`.
  - `SamsaraDriverAppAddress::make(int $integrationId, string $externalDriverId): string` → `samsara:{id}:{chofer}`; `::parse(string): ?array{integration_id: int, external_driver_id: string}`.
  - `NotificationChannelFactory::samsaraDriverApp()`.
  - Trait de tests `Tests\Feature\Domains\Drivers\Hos\HosTenantFixtures`: `hosIntegration(bool $feature = true): TenantIntegration`, `hosDriver(TenantIntegration $integration, string $externalId = '58072405', string $vehicleExternalId = '281', ?string $phone = '+5215512345678'): array{0: Driver, 1: Asset}`, `hosChannels(): void`, `fakeTwilio(): \stdClass` (`->messages`, `->calls`), `fakeAppMessages(int $status = 200): void`.

- [ ] **Step 1: Fixtures de tests** `tests/Feature/Domains/Drivers/Hos/HosTenantFixtures.php`:

```php
<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverExternalReference;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Notifications\Channels\TwilioMessenger;
use App\Domains\Notifications\Channels\TwilioVoiceCaller;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Mockery;

/**
 * Tenant con integración Samsara, chofer vinculado, canales de la escalera y
 * proveedores falsos para las pruebas de insistencia HOS (PR 2).
 */
trait HosTenantFixtures
{
    protected function hosIntegration(bool $feature = true): TenantIntegration
    {
        $user = User::factory()->create();
        $provider = IntegrationProvider::where('code', 'samsara')->first() ?? IntegrationProvider::factory()->samsara()->create();
        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $user->currentTeam->id, 'provider_id' => $provider->id, 'name' => 'Samsara',
            'status' => 'active', 'auth_type' => 'api_key', 'credentials_encrypted' => '',
        ]);
        IntegrationCredential::create(['tenant_integration_id' => $integration->id, 'key' => 'api_token', 'value_encrypted' => 'sk-test']);

        if ($feature) {
            TenantFeature::factory()->create(['team_id' => $integration->team_id, 'feature_key' => HosMonitoringConfig::FEATURE_KEY, 'enabled' => true]);
        }

        return $integration->load('provider');
    }

    /**
     * @return array{0: Driver, 1: Asset}
     */
    protected function hosDriver(TenantIntegration $integration, string $externalId = '58072405', string $vehicleExternalId = '281', ?string $phone = '+5215512345678'): array
    {
        $driver = Driver::factory()->create(['team_id' => $integration->team_id, 'full_name' => 'Juan Pérez Secreto', 'phone' => $phone]);
        DriverExternalReference::factory()->create(['driver_id' => $driver->id, 'provider_id' => $integration->provider_id, 'external_id' => $externalId, 'external_type' => 'driver']);
        $asset = Asset::factory()->create(['team_id' => $integration->team_id]);
        AssetExternalReference::factory()->create(['asset_id' => $asset->id, 'provider_id' => $integration->provider_id, 'external_id' => $vehicleExternalId, 'external_type' => 'vehicle']);

        return [$driver, $asset];
    }

    /** Canales de plataforma de la escalera por defecto. */
    protected function hosChannels(): void
    {
        NotificationChannel::factory()->samsaraDriverApp()->create();
        NotificationChannel::factory()->whatsapp()->create(['config_json' => ['from' => 'whatsapp:+14155238886']]);
        NotificationChannel::factory()->voice()->create();
    }

    /** Twilio falso: registra mensajes (WhatsApp/SMS) y llamadas. */
    protected function fakeTwilio(): \stdClass
    {
        config()->set('services.twilio.account_sid', 'AC123');
        config()->set('services.twilio.auth_token', 'tok-456');

        $sent = new \stdClass;
        $sent->messages = [];
        $sent->calls = [];

        $messenger = Mockery::mock(TwilioMessenger::class);
        $messenger->shouldReceive('createMessage')->andReturnUsing(function (string $to, array $params) use ($sent) {
            $sent->messages[] = ['to' => $to, 'params' => $params];

            return (object) ['sid' => 'SM'.bin2hex(random_bytes(16)), 'status' => 'queued', 'numSegments' => '1'];
        });
        $this->app->instance(TwilioMessenger::class, $messenger);

        $caller = Mockery::mock(TwilioVoiceCaller::class);
        $caller->shouldReceive('createCall')->andReturnUsing(function (string $to, string $from, array $params) use ($sent) {
            $sent->calls[] = ['to' => $to, 'params' => $params];

            return (object) ['sid' => 'CA'.bin2hex(random_bytes(16)), 'status' => 'queued'];
        });
        $this->app->instance(TwilioVoiceCaller::class, $caller);

        return $sent;
    }

    /** Un segundo Http::fake no reemplaza al primero: llamarlo una vez por test. */
    protected function fakeAppMessages(int $status = 200): void
    {
        Http::fake([
            'api.samsara.com/v1/fleet/messages' => Http::response($status === 200 ? ['data' => []] : ['message' => 'forbidden'], $status),
        ]);
    }
}
```

- [ ] **Step 2: Test que falla** `tests/Feature/Domains/Notifications/SamsaraDriverAppChannelTest.php`:

```php
<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Drivers\Models\Driver;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Exceptions\ProviderUnauthorized;
use App\Domains\Integrations\Exceptions\ProviderUnavailable;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Notifications\Actions\DispatchNotification;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Events\NotificationFailed;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Support\ProvidedChannels;
use App\Domains\Notifications\Support\SamsaraDriverAppAddress;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\Feature\Domains\Drivers\Hos\HosTenantFixtures;
use Tests\TestCase;

/**
 * Canal gratis a la app del chofer en Samsara (`POST /v1/fleet/messages`):
 * sin medidor, sin cargo Twilio y sólo para destinatarios `driver`.
 */
class SamsaraDriverAppChannelTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, HosTenantFixtures, RefreshDatabase;

    public function test_the_adapter_posts_the_message_to_the_driver_app(): void
    {
        $this->fakeAppMessages();
        $integration = $this->hosIntegration(feature: false);

        app(ProviderAdapter::class)->sendDriverMessage($integration, '58072405', 'Hola, soy SAM');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://api.samsara.com/v1/fleet/messages'
            && $request['driverIds'] === [58072405]
            && $request['text'] === 'Hola, soy SAM'
            && $request->hasHeader('Authorization', 'Bearer sk-test'));
    }

    public function test_the_adapter_types_its_failures(): void
    {
        $integration = $this->hosIntegration(feature: false);
        Http::fake(['api.samsara.com/v1/fleet/messages' => Http::sequence()
            ->push(['message' => 'missing scope'], 403)
            ->push([], 503)]);

        try {
            app(ProviderAdapter::class)->sendDriverMessage($integration, '58072405', 'Hola');
            $this->fail('Un 403 debe ser ProviderUnauthorized.');
        } catch (ProviderUnauthorized) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(ProviderUnavailable::class);
        app(ProviderAdapter::class)->sendDriverMessage($integration, '58072405', 'Hola');
    }

    public function test_a_driver_recipient_gets_the_message_in_the_samsara_app_without_metering(): void
    {
        $this->fakeAppMessages();
        NotificationChannel::factory()->samsaraDriverApp()->create();
        $integration = $this->hosIntegration(feature: false);
        [$driver] = $this->hosDriver($integration);

        $notification = $this->notifyDriver($integration, $driver, SamsaraDriverAppAddress::make($integration->id, '58072405'));

        $delivery = NotificationDelivery::withoutGlobalScopes()->sole();
        $this->assertSame(DeliveryStatus::Delivered, $delivery->status);
        $this->assertSame(NotificationStatus::Sent, $notification->fresh()->status);
        Http::assertSentCount(1);
        $this->assertSame(0, MessagingCharge::withoutGlobalScopes()->count());

        $sent = $this->assertSystemLogged('notifications.delivery.sent');
        $this->assertSame('samsara_driver_app', $sent['input']['channel_type']);
        $this->assertNull($sent['result']['usage_meter_code']);
        $this->assertFalse($sent['result']['usage_metered']);
        $this->assertNoSensitiveDataLogged();
        $this->assertStringNotContainsString('Secreto', json_encode($this->systemLogEntries()));
    }

    public function test_an_address_pointing_at_another_tenants_integration_is_never_used(): void
    {
        Http::fake();
        Event::fake([NotificationFailed::class]);
        NotificationChannel::factory()->samsaraDriverApp()->create();
        $mine = $this->hosIntegration(feature: false);
        $foreign = $this->hosIntegration(feature: false);
        [$driver] = $this->hosDriver($mine);

        $this->assertNoTenantLeak($mine->team_id, fn () => $this->notifyDriver($mine, $driver, SamsaraDriverAppAddress::make($foreign->id, '58072405')));

        $delivery = NotificationDelivery::withoutGlobalScopes()->sole();
        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertTrue((bool) $delivery->permanent_failure);
        Http::assertNothingSent();
        $this->assertSame('integration_missing', $this->assertSystemLogged('notifications.delivery.failed')['result']['provider_error_code']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_only_drivers_have_a_samsara_app_address(): void
    {
        $metadata = [NotificationRecipient::SAMSARA_APP_ADDRESS_KEY => 'samsara:1:2'];

        $user = new NotificationRecipient(['recipient_type' => RecipientType::User, 'address' => 'ops@example.com', 'metadata_json' => $metadata]);
        $this->assertNull($user->addressForChannel(ChannelType::SamsaraDriverApp));

        $driver = new NotificationRecipient(['recipient_type' => RecipientType::Driver, 'address' => 'driver:9', 'metadata_json' => $metadata]);
        $this->assertSame('samsara:1:2', $driver->addressForChannel(ChannelType::SamsaraDriverApp));

        $withoutApp = new NotificationRecipient(['recipient_type' => RecipientType::Driver, 'address' => 'driver:9']);
        $this->assertNull($withoutApp->addressForChannel(ChannelType::SamsaraDriverApp));
    }

    public function test_the_address_round_trips_and_rejects_anything_else(): void
    {
        $this->assertSame(
            ['integration_id' => 7, 'external_driver_id' => '58072405'],
            SamsaraDriverAppAddress::parse(SamsaraDriverAppAddress::make(7, '58072405')),
        );
        $this->assertNull(SamsaraDriverAppAddress::parse('ops@example.com'));
        $this->assertNull(SamsaraDriverAppAddress::parse('samsara:7:abc'));
    }

    public function test_the_tenant_ui_never_offers_the_driver_only_channel(): void
    {
        NotificationChannel::factory()->samsaraDriverApp()->create();
        NotificationChannel::factory()->sms()->create();

        $this->assertSame(['sms'], array_map(fn (ChannelType $type) => $type->value, app(ProvidedChannels::class)->types()));
        $this->assertSame('App de Samsara', ChannelType::SamsaraDriverApp->label());
    }

    private function notifyDriver(TenantIntegration $integration, Driver $driver, string $appAddress): Notification
    {
        return TenantContext::for($integration->team_id, function () use ($integration, $driver, $appAddress): Notification {
            $notification = app(SendNotification::class)->execute(
                teamId: $integration->team_id,
                notificationType: 'manual.driver_message',
                sourceType: NotificationSourceType::Manual,
                sourceReferenceId: null,
                priority: NotificationPriority::High,
                triggeredByType: NotificationTriggeredByType::System,
                triggeredById: null,
                eventKey: 'test:driver-app:'.$driver->id,
                payload: [
                    'force_channels' => ['samsara_driver_app'],
                    'recipients' => [[
                        'recipient_type' => 'driver',
                        'address' => 'driver:'.$driver->id,
                        'name' => $driver->full_name,
                        'recipient_reference_id' => (string) $driver->id,
                        'metadata' => [NotificationRecipient::SAMSARA_APP_ADDRESS_KEY => $appAddress],
                    ]],
                ],
                subject: 'Prueba',
                bodyPreview: 'SAM: mensaje de prueba para el chofer.',
                dispatchJob: false,
            );

            return app(DispatchNotification::class)->execute($notification);
        });
    }
}
```

En `tests/Unit/Domains/Notifications/ChannelTypeUsageMeterCodeTest.php` cambiar el PHPDoc del provider a `@return array<string, array{ChannelType, ?string}>`, la firma a `test_channel_type_maps_to_usage_meter_code(ChannelType $channelType, ?string $expected)` y agregar al provider:

```php
            'samsara driver app is free: no meter' => [ChannelType::SamsaraDriverApp, null],
```

En `tests/Feature/Domains/Notifications/PlatformChannelSeederTest.php`: la lista de tipos pasa a `['email', 'web', 'sms', 'whatsapp', 'voice', 'push', 'samsara_driver_app']` y `assertSame(6, ...)` → `assertSame(7, ...)`.

- [ ] **Step 3:** Run `php artisan test --compact --filter='SamsaraDriverAppChannelTest|ChannelTypeUsageMeterCodeTest|PlatformChannelSeederTest'` → FAIL (`ChannelType::SamsaraDriverApp` no existe).

- [ ] **Step 4: Adapter.** En `ProviderAdapter.php`, al final de la interfaz:

```php
    /**
     * Sends a text to one driver's provider app (Samsara legacy
     * `POST /v1/fleet/messages`, scope "Write Messages"). Throws
     * {@see ProviderUnauthorized} when the token lacks the scope (401/403) and
     * the rest of the typed {@see ProviderRequestFailed} family otherwise.
     */
    public function sendDriverMessage(TenantIntegration $integration, string $externalDriverId, string $text): void;
```

(agregar `use App\Domains\Integrations\Exceptions\ProviderRequestFailed;` y `use App\Domains\Integrations\Exceptions\ProviderUnauthorized;` si el archivo no los importa para el PHPDoc).

En `NullProviderAdapter.php`, al final:

```php
    public function sendDriverMessage(TenantIntegration $integration, string $externalDriverId, string $text): void
    {
        throw new ProviderRequestFailedException('POST /v1/fleet/messages', 501, 'Este proveedor no admite mensajes a la app del chofer.');
    }
```

En `ProviderAdapterManager.php`, después de `fetchTags()`:

```php
    public function sendDriverMessage(TenantIntegration $integration, string $externalDriverId, string $text): void
    {
        $this->forIntegration($integration)->sendDriverMessage($integration, $externalDriverId, $text);
    }
```

En `SamsaraAdapter.php` agregar la constante junto a las demás (`MAX_PAGES`):

```php
    /** Samsara rejects driver-app messages longer than this. */
    public const int DRIVER_MESSAGE_MAX_LENGTH = 2500;
```

y después de `fetchTags()`:

```php
    public function sendDriverMessage(TenantIntegration $integration, string $externalDriverId, string $text): void
    {
        $endpoint = 'POST /v1/fleet/messages';

        // La API legacy recibe ids numéricos (int64).
        if (preg_match('/^\d+$/', $externalDriverId) !== 1) {
            throw new ProviderRequestFailedException($endpoint, 422, 'El id de chofer de Samsara no es numérico.');
        }

        $token = $this->resolveToken($integration);

        if ($token === null || $token === '') {
            throw new ProviderUnauthorized('No hay token de API configurado para esta integración de Samsara.');
        }

        try {
            $response = $this->client($token)->post('/v1/fleet/messages', [
                'driverIds' => [(int) $externalDriverId],
                'text' => mb_substr($text, 0, self::DRIVER_MESSAGE_MAX_LENGTH),
            ]);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable('Could not reach Samsara: '.SafeErrorMessage::from($e), previous: $e);
        }

        $status = $response->status();

        if ($status === 429) {
            $retryAfter = $response->header('Retry-After');

            throw new ProviderRateLimited(max(0.0, (float) ($retryAfter === '' || $retryAfter === '0' ? 1 : $retryAfter)));
        }

        if ($status === 401 || $status === 403) {
            throw new ProviderUnauthorized("Samsara rejected the driver message (HTTP {$status}).");
        }

        if ($status >= 500) {
            throw new ProviderUnavailable("Samsara returned HTTP {$status}.");
        }

        if (! $response->successful()) {
            throw ProviderRequestFailedException::fromResponse($endpoint, $response);
        }
    }
```

- [ ] **Step 5: Enums.** `ChannelType.php`: agregar `case SamsaraDriverApp = 'samsara_driver_app';` después de `Voice`; reemplazar `usageMeterCode()`:

```php
    /**
     * Messaging channels carry a per-message provider fee (Twilio) and bill on
     * their own meter; the rest stay on the generic outbound_notifications
     * meter. Distinct from `voice_calls`, which meters incident DTMF
     * verification calls ({@see PlaceVerificationCallJob}). A message to the
     * driver's Samsara app costs SAM nothing and is not billed: no meter.
     */
    public function usageMeterCode(): ?string
    {
        return match ($this) {
            self::Sms => 'sms_messages',
            self::Whatsapp => 'whatsapp_messages',
            self::Voice => 'voice_notification_calls',
            self::SamsaraDriverApp => null,
            default => 'outbound_notifications',
        };
    }
```

y en `label()` agregar `self::SamsaraDriverApp => 'App de Samsara',`. `RecipientType.php`: agregar `case Driver = 'driver';`.

- [ ] **Step 6: Dirección y driver.** `app/Domains/Notifications/Support/SamsaraDriverAppAddress.php`:

```php
<?php

namespace App\Domains\Notifications\Support;

/**
 * Destino del canal `samsara_driver_app`: la integración Samsara del tenant
 * y el id del chofer en Samsara, como `samsara:{integration}:{chofer}`. El
 * driver resuelve la integración DENTRO del team de la entrega: una
 * dirección con el id de la integración de otro tenant no encuentra nada.
 */
final class SamsaraDriverAppAddress
{
    public static function make(int $integrationId, string $externalDriverId): string
    {
        return "samsara:{$integrationId}:{$externalDriverId}";
    }

    /**
     * @return array{integration_id: int, external_driver_id: string}|null
     */
    public static function parse(string $address): ?array
    {
        if (preg_match('/^samsara:(\d+):(\d+)$/', trim($address), $matches) !== 1) {
            return null;
        }

        return ['integration_id' => (int) $matches[1], 'external_driver_id' => $matches[2]];
    }
}
```

`app/Domains/Notifications/Channels/SamsaraDriverAppNotificationDriver.php`:

```php
<?php

namespace App\Domains\Notifications\Channels;

use App\Contracts\Notifications\NotificationDriver;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Exceptions\ProviderRequestFailed;
use App\Domains\Integrations\Exceptions\ProviderRequestFailedException;
use App\Domains\Integrations\Exceptions\ProviderUnauthorized;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Support\SamsaraDriverAppAddress;
use App\Support\SafeErrorMessage;

/**
 * Mensaje a la app del chofer en Samsara (monitoreo HOS, spec 2026-10-04
 * §3.10). Gratis: no hay recurso Twilio ni medidor. Síncrono: si Samsara lo
 * acepta, la entrega queda `delivered`.
 *
 * La integración sale de la dirección, pero sólo se usa si es del team de
 * la entrega (la dirección es un id de proveedor: nunca se confía en ella
 * sola). Un 401/403 (token sin "Write Messages") es permanente.
 *
 * Corre dentro del TenantContext de la entrega (AttemptDelivery).
 */
class SamsaraDriverAppNotificationDriver implements NotificationDriver
{
    public function __construct(
        private readonly ProviderAdapter $providers,
    ) {}

    public function send(RenderedNotification $notification, NotificationChannel $channel): DeliveryResult
    {
        $target = SamsaraDriverAppAddress::parse($notification->address);

        if ($target === null) {
            return DeliveryResult::failure('samsara driver app address is invalid', permanent: true, providerErrorCode: 'invalid_address');
        }

        $teamId = $notification->deliveryId !== null
            ? NotificationDelivery::query()->whereKey($notification->deliveryId)->value('team_id')
            : null;

        $integration = is_numeric($teamId)
            ? TenantIntegration::query()
                ->where('team_id', (int) $teamId)
                ->where('status', TenantIntegrationStatus::Active)
                ->with('provider')
                ->find($target['integration_id'])
            : null;

        if ($integration === null || $integration->provider?->code !== 'samsara') {
            return DeliveryResult::failure('samsara integration not found for this team', permanent: true, providerErrorCode: 'integration_missing');
        }

        try {
            $this->providers->sendDriverMessage($integration, $target['external_driver_id'], $notification->body);
        } catch (ProviderUnauthorized $e) {
            return DeliveryResult::failure(SafeErrorMessage::from($e), permanent: true, providerErrorCode: 'unauthorized');
        } catch (ProviderRequestFailed|ProviderRequestFailedException $e) {
            return DeliveryResult::failure(SafeErrorMessage::from($e), providerErrorCode: 'provider_error');
        }

        return DeliveryResult::success();
    }
}
```

- [ ] **Step 7: Cableado de Notifications.**
  - `ChannelDriverRegistry.php`: agregar `ChannelType::SamsaraDriverApp => $this->container->make(SamsaraDriverAppNotificationDriver::class),` al `match`.
  - `NotificationRecipient.php`: constante `public const string SAMSARA_APP_ADDRESS_KEY = 'samsara_app_address';`; en `addressForChannel()` agregar el brazo antes de `default`:

```php
            ChannelType::SamsaraDriverApp => $this->recipient_type === RecipientType::Driver
                ? $this->samsaraAppAddress()
                : null,
```

    y el método privado:

```php
    /**
     * Dirección de la app del chofer que dejó quien creó el aviso
     * (SamsaraDriverAppAddress), o null.
     */
    private function samsaraAppAddress(): ?string
    {
        $metadata = is_array($this->metadata_json) ? $this->metadata_json : [];
        $address = $metadata[self::SAMSARA_APP_ADDRESS_KEY] ?? null;

        return is_string($address) ? self::presentOrNull($address) : null;
    }
```

    Actualizar el docblock de `addressForChannel()` agregando: "la app de Samsara sólo existe para choferes (`metadata_json.samsara_app_address`)".
  - `AttemptDelivery.php`, dentro de `if ($result->success)`, reemplazar el bloque de medición y el resultado del log:

```php
            $meterCode = $channel->channel_type->usageMeterCode();

            // Un canal sin medidor (la app del chofer en Samsara) no se cobra.
            $usageMetered = $meterCode !== null && $this->recordUsage->execute(
                teamId: $delivery->team_id,
                meterCode: $meterCode,
                quantity: $channel->channel_type === ChannelType::Sms ? max(1, (int) $result->segments) : 1,
                eventKey: $usageEventKey,
            );
```

    y en `SystemLog::ok('notifications.delivery.sent', ...)` usar `'usage_meter_code' => $meterCode,`.
  - `ProvidedChannels.php`: en `types()` cambiar el filtro a

```php
            fn (ChannelType $type): bool => $type !== ChannelType::SamsaraDriverApp
                && in_array($type->value, $active, true),
```

    y agregar al docblock: "La app del chofer en Samsara sólo sirve a choferes (avisos HOS): nunca se ofrece para avisos al equipo."

- [ ] **Step 8: Canal de plataforma.** `PlatformChannelSeeder.php`: agregar a `$channels`

```php
            ['code' => 'sam_samsara_driver_app', 'name' => 'App del chofer (Samsara)', 'provider' => 'samsara', 'channel_type' => ChannelType::SamsaraDriverApp],
```

`NotificationChannelFactory.php`, después de `voice()`:

```php
    public function samsaraDriverApp(): static
    {
        return $this->state(fn () => [
            'channel_type' => ChannelType::SamsaraDriverApp,
            'provider' => 'samsara',
        ]);
    }
```

`database/migrations/2026_10_11_100100_seed_platform_samsara_driver_app_channel.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Canal de plataforma "App del chofer (Samsara)" (monitoreo HOS PR 2) en
 * entornos ya sembrados. Idempotente y sin pisar una fila existente, igual
 * que PlatformChannelSeeder; en una base sin canales de plataforma
 * (instalación nueva, tests) no hace nada: ahí lo siembra el seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('notification_channels')->where('code', 'like', 'sam\\_%')->exists()) {
            return;
        }

        $exists = DB::table('notification_channels')
            ->where('code', 'sam_samsara_driver_app')
            ->orWhere('channel_type', 'samsara_driver_app')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('notification_channels')->insert([
            'code' => 'sam_samsara_driver_app',
            'name' => 'App del chofer (Samsara)',
            'provider' => 'samsara',
            'channel_type' => 'samsara_driver_app',
            'config_json' => null,
            'is_active' => true,
            'supports_priority' => false,
            'supports_template' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('notification_channels')->where('code', 'sam_samsara_driver_app')->delete();
    }
};
```

- [ ] **Step 9: Catálogo de logs.** En `docs/SAM/logging.md`, fila de `notifications.delivery.sent`, reemplazar `` `usage_meter_code`, `usage_metered` (`RecordMessagingUsage` lo midió) `` por `` `usage_meter_code` (null en `samsara_driver_app`: gratis, sin medidor), `usage_metered` (`RecordMessagingUsage` lo midió; false sin medidor) ``.

- [ ] **Step 10: Front (etiquetas e íconos).**
  - `resources/js/lib/labels.ts`, en `CHANNEL_LABELS` después de `voice: 'Llamada de voz',`: `samsara_driver_app: 'App de Samsara',`.
  - `resources/js/types/notifications.ts`, en `NotificationChannelType` agregar `| 'samsara_driver_app'` después de `| 'voice'`.
  - `notifications-table.tsx` y `detail/delivery-item.tsx`: importar `Truck` de `lucide-react` (en orden alfabético, después de `Smartphone,`) y agregar `samsara_driver_app: Truck,` a `CHANNEL_ICONS`.
  - Crear `resources/js/lib/labels.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { channelLabel } from '@/lib/labels';

describe('channelLabel', () => {
    it('nombra el canal de la app del chofer en Samsara', () => {
        expect(channelLabel('samsara_driver_app')).toBe('App de Samsara');
    });
});
```

- [ ] **Step 11:** Run `php artisan test --compact --filter='SamsaraDriverAppChannelTest|ChannelTypeUsageMeterCodeTest|PlatformChannelSeederTest|TenantConfigPageTest|NotificationPreferencesSettingsTest|LoggingConventionsTest'` → PASS. Run `npm run types:check && npx vitest run resources/js/lib/labels.test.ts` → PASS.
- [ ] **Step 12: Commit**

```bash
git add app/Domains/Integrations app/Domains/Notifications database/seeders/PlatformChannelSeeder.php database/factories/Domains/Notifications/NotificationChannelFactory.php database/migrations/2026_10_11_100100_seed_platform_samsara_driver_app_channel.php docs/SAM/logging.md resources/js tests/Feature/Domains/Drivers/Hos/HosTenantFixtures.php tests/Feature/Domains/Notifications tests/Unit/Domains/Notifications
git commit -m "feat: canal de mensajes a la app del chofer en samsara"
```

---

### Task 3: Fuente `hos_episode` en Notificaciones (sin silencio, sin fallback)

**Files:**
- Modify: `app/Domains/Notifications/Enums/NotificationSourceType.php`, `app/Domains/Notifications/Actions/SelectNotificationChannels.php`, `app/Domains/Notifications/Support/DeliveryEscalationGuard.php`, `app/Domains/Notifications/Support/NotificationTypeLabels.php`, `docs/SAM/logging.md`
- Test: Create `tests/Feature/Domains/Notifications/HosNudgeNotificationPolicyTest.php`

**Interfaces:**
- Produces: `NotificationSourceType::HosEpisode` (`'hos_episode'`); para esa fuente `SelectNotificationChannels::explain()` devuelve `calc.quiet_hours_active = false`, `calc.quiet_hours_source = 'bypassed'`; `DeliveryEscalationGuard::explain()` devuelve `reason = 'own_ladder'`; `NotificationTypeLabels::label('hos.nudge') = 'Recordatorio de horas de servicio al chofer'`. La preferencia de usuario sólo se lee para destinatarios `user`.

- [ ] **Step 1: Test que falla** `tests/Feature/Domains/Notifications/HosNudgeNotificationPolicyTest.php`:

```php
<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Actions\SelectNotificationChannels;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Events\NotificationFailed;
use App\Domains\Notifications\Jobs\FallbackNotificationChannelJob;
use App\Domains\Notifications\Jobs\RetryNotificationDeliveryJob;
use App\Domains\Notifications\Listeners\RetryOrFallbackOnNotificationFailed;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationPreference;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Support\DeliveryEscalationGuard;
use App\Domains\Notifications\Support\NotificationTypeLabels;
use App\Domains\TenantConfig\Models\TenantNotificationPolicy;
use App\Models\Team;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * Los avisos HOS al chofer son seguridad vial y van en ruta: no los calla el
 * horario silencioso, y su escalera es su propia insistencia (sin reintento
 * ni fallback de la política del tenant).
 */
class HosNudgeNotificationPolicyTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        foreach ([ChannelType::Email, ChannelType::Sms, ChannelType::Whatsapp, ChannelType::Voice] as $type) {
            NotificationChannel::factory()->create(['channel_type' => $type, 'is_active' => true]);
        }
    }

    public function test_driver_hos_nudges_ignore_quiet_hours(): void
    {
        $team = $this->quietTeam();
        $this->travelTo(Carbon::parse('2026-09-27 23:30:00', $team->timezone));

        [$hos, $hosRecipient] = $this->nudge($team, NotificationSourceType::HosEpisode);
        $selection = app(SelectNotificationChannels::class)->explain($hos, $hosRecipient);

        $this->assertEqualsCanonicalizing(['whatsapp', 'voice'], array_map(fn (NotificationChannel $channel) => $channel->channel_type->value, $selection['channels']));
        $this->assertFalse($selection['calc']['quiet_hours_active']);
        $this->assertSame('bypassed', $selection['calc']['quiet_hours_source']);

        // El mismo aviso de otra fuente sí se calla.
        [$manual, $manualRecipient] = $this->nudge($team, NotificationSourceType::Manual);
        $this->assertSame([], app(SelectNotificationChannels::class)->execute($manual, $manualRecipient));
    }

    public function test_a_driver_never_inherits_the_preference_of_a_user_with_the_same_id(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $team->forceFill(['timezone' => 'UTC'])->save();
        NotificationPreference::factory()->create([
            'team_id' => $team->id, 'user_id' => $user->id, 'notification_type' => 'hos.nudge',
            'allowed_channels_json' => ['email'], 'quiet_hours_json' => ['start' => '00:00', 'end' => '23:59'],
        ]);
        [$notification, $recipient] = $this->nudge($team, NotificationSourceType::Manual, (string) $user->id);

        $selection = app(SelectNotificationChannels::class)->explain($notification, $recipient);

        $this->assertSame('none', $selection['calc']['quiet_hours_source']);
    }

    public function test_a_failed_hos_nudge_is_neither_retried_nor_moved_to_another_channel(): void
    {
        Queue::fake();
        $team = User::factory()->create()->currentTeam;
        $notification = Notification::factory()->create(['team_id' => $team->id, 'source_type' => NotificationSourceType::HosEpisode, 'notification_type' => 'hos.nudge']);
        $delivery = NotificationDelivery::factory()->failed()->create(['notification_id' => $notification->id, 'permanent_failure' => true]);

        TenantContext::for($team->id, fn () => $this->assertSame('own_ladder', DeliveryEscalationGuard::blockReason($delivery)));

        app(RetryOrFallbackOnNotificationFailed::class)->handle(new NotificationFailed($team->id, $notification->id, $delivery->id, 'whatsapp', 'boom'));

        Queue::assertNotPushed(RetryNotificationDeliveryJob::class);
        Queue::assertNotPushed(FallbackNotificationChannelJob::class);
        $this->assertSame('own_ladder', $this->assertSystemLogged('notifications.escalation_guard.blocked')['reason']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_nudge_type_has_a_human_label(): void
    {
        $this->assertSame('Recordatorio de horas de servicio al chofer', app(NotificationTypeLabels::class)->label('hos.nudge'));
    }

    private function quietTeam(): Team
    {
        $team = User::factory()->create()->currentTeam;
        $team->forceFill(['timezone' => 'America/Mexico_City'])->save();

        TenantNotificationPolicy::factory()->create([
            'team_id' => $team->id, 'policy_code' => 'default', 'notification_type' => null, 'priority' => null,
            'allowed_channels_json' => ['email', 'sms', 'whatsapp', 'voice'],
            'quiet_hours_json' => ['start' => '22:00', 'end' => '07:00'],
        ]);

        return $team;
    }

    /**
     * @return array{0: Notification, 1: NotificationRecipient}
     */
    private function nudge(Team $team, NotificationSourceType $source, ?string $referenceId = '1'): array
    {
        $notification = Notification::factory()->create([
            'team_id' => $team->id, 'source_type' => $source, 'notification_type' => 'hos.nudge',
            'priority' => NotificationPriority::High, 'payload_json' => ['force_channels' => ['whatsapp', 'voice']],
        ]);
        $recipient = NotificationRecipient::factory()->create([
            'notification_id' => $notification->id, 'team_id' => $team->id, 'recipient_type' => RecipientType::Driver,
            'recipient_reference_id' => $referenceId, 'address' => 'driver:'.$referenceId, 'phone' => '+5215512345678',
        ]);

        return [$notification, $recipient];
    }
}
```

- [ ] **Step 2:** Run `php artisan test --compact --filter=HosNudgeNotificationPolicyTest` → FAIL.

- [ ] **Step 3: Implementación.**
  - `NotificationSourceType.php`: agregar `case HosEpisode = 'hos_episode';`.
  - `SelectNotificationChannels.php`: agregar `use App\Domains\Notifications\Enums\NotificationSourceType;` y `use App\Domains\Notifications\Enums\RecipientType;`. Reemplazar `$quiet = $this->insideQuietHours($team, $policy, $preference);` por:

```php
        // Avisos HOS al chofer (spec 2026-10-04 §3.10): seguridad vial y el
        // chofer está en ruta, así que no hay horario silencioso.
        $quiet = $notification->source_type === NotificationSourceType::HosEpisode
            ? ['active' => false, 'source' => 'bypassed']
            : $this->insideQuietHours($team, $policy, $preference);
```

    En `resolvePreference()`, reemplazar la primera condición por:

```php
        $userId = $recipient->recipient_type === RecipientType::User
            && $recipient->recipient_reference_id !== null
            && is_numeric($recipient->recipient_reference_id)
            ? (int) $recipient->recipient_reference_id
            : null;
```

    (un chofer también trae `recipient_reference_id` numérico: es su id de `drivers`, no de `users`).
  - `DeliveryEscalationGuard.php`: justo después del bloque `TenantCanSend::blockedReason(...)`:

```php
        // La escalera HOS es su propia insistencia: cada escalón ya sube de
        // canal a su hora. Un reintento o el fallback de la política del
        // tenant mandarían avisos pagados fuera de esa escalera.
        if ($notification->source_type === NotificationSourceType::HosEpisode) {
            return ['reason' => 'own_ladder', 'calc' => $calc];
        }
```

    y agregar al docblock de la clase el punto "- la notificación es un aviso HOS al chofer (`own_ladder`): la escalera HOS decide el siguiente intento."
  - `NotificationTypeLabels.php`: en `FIXED` agregar `'hos.nudge' => 'Recordatorio de horas de servicio al chofer',`.

- [ ] **Step 4: Catálogo.** En `docs/SAM/logging.md`: fila `notifications.channels.selected`, reemplazar `` `quiet_hours_source` (`user_preference`/`tenant_policy`/`none`) `` por `` `quiet_hours_source` (`user_preference`/`tenant_policy`/`none`/`bypassed`: avisos HOS al chofer, fuente `hos_episode`) ``; fila `notifications.escalation_guard.blocked`, reemplazar `` `incident_handled`, `recipient_reached` | `` por `` `incident_handled`, `recipient_reached`, `own_ladder` (aviso HOS: su escalera decide) | ``.
- [ ] **Step 5:** Run `php artisan test --compact --filter='HosNudgeNotificationPolicyTest|QuietHoursChannelSelectionTest|RetryAndFallbackTest|LoggingConventionsTest'` → PASS.
- [ ] **Step 6: Commit**

```bash
git add app/Domains/Notifications docs/SAM/logging.md tests/Feature/Domains/Notifications/HosNudgeNotificationPolicyTest.php
git commit -m "feat: avisos hos al chofer sin horario silencioso ni fallback"
```

---

### Task 4: Textos de los avisos HOS

**Files:**
- Create: `app/Domains/Drivers/Enums/HosNotice.php`, `app/Domains/Drivers/Support/HosNoticeCopy.php`
- Test: Create `tests/Unit/Domains/Drivers/HosNoticeCopyTest.php`

**Interfaces:**
- Produces: `HosNotice` (`break_lead|break_limit|break_insist|drive_lead|drive_limit|drive_insist|shift_lead|shift_limit|shift_insist|cycle_lead|rest_complete|violation`) con `static lead(HosSituation): self` y `static limit(HosSituation, bool $insist): self`. `HosNoticeCopy::for(HosNotice $notice, ?int $amount = null): array{subject: string, body: string, spoken: string}` (`$amount` = minutos o, en `cycle_lead`, horas). `HosNoticeCopy::corrected(HosSituation): string` (línea de tiempo del incidente).

- [ ] **Step 1: Test que falla** `tests/Unit/Domains/Drivers/HosNoticeCopyTest.php`:

```php
<?php

namespace Tests\Unit\Domains\Drivers;

use App\Domains\Drivers\Enums\HosNotice;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Support\HosNoticeCopy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HosNoticeCopyTest extends TestCase
{
    /**
     * @return array<string, array{HosNotice}>
     */
    public static function notices(): array
    {
        $cases = [];

        foreach (HosNotice::cases() as $notice) {
            $cases[$notice->value] = [$notice];
        }

        return $cases;
    }

    #[DataProvider('notices')]
    public function test_every_notice_fits_an_sms_and_has_a_spoken_version(HosNotice $notice): void
    {
        $copy = HosNoticeCopy::for($notice, 30);

        $this->assertNotSame('', $copy['subject']);
        $this->assertStringStartsWith('SAM: ', $copy['body']);
        $this->assertLessThanOrEqual(160, mb_strlen($copy['body']));
        $this->assertStringStartsWith('Hola, te llama SAM. ', $copy['spoken']);
        // La voz no lee abreviaturas ni inglés.
        $this->assertDoesNotMatchRegularExpression('/\b(min|h|break|HOS)\b/u', $copy['spoken']);
    }

    public function test_minutes_and_hours_read_naturally(): void
    {
        $this->assertStringContainsString('Te quedan 15 min para tu break obligatorio de 30 min', HosNoticeCopy::for(HosNotice::BreakLead, 15)['body']);
        $this->assertStringContainsString('Te quedan 15 minutos', HosNoticeCopy::for(HosNotice::BreakLead, 15)['spoken']);
        $this->assertStringContainsString('en 1 minuto.', HosNoticeCopy::for(HosNotice::DriveLead, 1)['spoken']);
        $this->assertStringContainsString('Te quedan 5 h en tu ciclo de 70 h', HosNoticeCopy::for(HosNotice::CycleLead, 5)['body']);
        $this->assertStringContainsString('Te quedan 1 hora en tu ciclo', HosNoticeCopy::for(HosNotice::CycleLead, 1)['spoken']);
    }

    public function test_the_notice_follows_the_situation_and_the_ladder_position(): void
    {
        $this->assertSame(HosNotice::BreakLead, HosNotice::lead(HosSituation::BreakDue));
        $this->assertSame(HosNotice::ShiftLead, HosNotice::lead(HosSituation::ShiftLimit));
        $this->assertSame(HosNotice::DriveLimit, HosNotice::limit(HosSituation::DriveLimit, insist: false));
        $this->assertSame(HosNotice::DriveInsist, HosNotice::limit(HosSituation::DriveLimit, insist: true));
        $this->assertSame(HosNotice::BreakInsist, HosNotice::limit(HosSituation::BreakDue, insist: true));
    }

    public function test_every_situation_has_a_correction_line_for_the_incident(): void
    {
        foreach (HosSituation::cases() as $situation) {
            $this->assertNotSame('', HosNoticeCopy::corrected($situation));
            $this->assertStringEndsWith('.', HosNoticeCopy::corrected($situation));
        }

        $this->assertSame('El chofer ya tomó su break de 30 min.', HosNoticeCopy::corrected(HosSituation::BreakDue));
    }
}
```

- [ ] **Step 2:** Run `php artisan test --compact --filter=HosNoticeCopyTest` → FAIL.

- [ ] **Step 3: `app/Domains/Drivers/Enums/HosNotice.php`**

```php
<?php

namespace App\Domains\Drivers\Enums;

/** Which reminder text a step of the HOS ladder sends to the driver. */
enum HosNotice: string
{
    case BreakLead = 'break_lead';
    case BreakLimit = 'break_limit';
    case BreakInsist = 'break_insist';
    case DriveLead = 'drive_lead';
    case DriveLimit = 'drive_limit';
    case DriveInsist = 'drive_insist';
    case ShiftLead = 'shift_lead';
    case ShiftLimit = 'shift_limit';
    case ShiftInsist = 'shift_insist';
    case CycleLead = 'cycle_lead';
    case RestComplete = 'rest_complete';
    case Violation = 'violation';

    /** Warning before a limit (30/15 min) or a cycle threshold. */
    public static function lead(HosSituation $situation): self
    {
        return match ($situation) {
            HosSituation::DriveLimit => self::DriveLead,
            HosSituation::ShiftLimit => self::ShiftLead,
            HosSituation::CycleLimit => self::CycleLead,
            HosSituation::RestComplete => self::RestComplete,
            HosSituation::Violation => self::Violation,
            HosSituation::BreakDue => self::BreakLead,
        };
    }

    /** Limit reached: the first step says so, the next ones insist. */
    public static function limit(HosSituation $situation, bool $insist): self
    {
        return match ($situation) {
            HosSituation::DriveLimit => $insist ? self::DriveInsist : self::DriveLimit,
            HosSituation::ShiftLimit => $insist ? self::ShiftInsist : self::ShiftLimit,
            HosSituation::CycleLimit => self::CycleLead,
            HosSituation::RestComplete => self::RestComplete,
            HosSituation::Violation => self::Violation,
            HosSituation::BreakDue => $insist ? self::BreakInsist : self::BreakLimit,
        };
    }
}
```

- [ ] **Step 4: `app/Domains/Drivers/Support/HosNoticeCopy.php`**

```php
<?php

namespace App\Domains\Drivers\Support;

use App\Domains\Drivers\Enums\HosNotice;
use App\Domains\Drivers\Enums\HosSituation;

/**
 * Lo que SAM le dice al chofer sobre sus horas de servicio, en español de
 * México, de tú y con la voz de SAM (mismo estilo que IncidentNoticeCopy).
 *
 *  - `subject`: título del aviso (variable {{1}} de la plantilla de WhatsApp).
 *  - `body`: texto de la app de Samsara, WhatsApp o SMS. Empieza con "SAM:"
 *    y cabe en un SMS (≤ 160).
 *  - `spoken`: lo que lee la llamada. Saluda y va sin abreviaturas ni
 *    inglés ("descanso de media hora", no "break de 30 min").
 *
 * Nunca lleva el nombre del chofer ni datos de la unidad: le llega a él.
 */
final class HosNoticeCopy
{
    /**
     * @param  int|null  $amount  minutos que le quedan (avisos previos) u horas (ciclo)
     * @return array{subject: string, body: string, spoken: string}
     */
    public static function for(HosNotice $notice, ?int $amount = null): array
    {
        $minutes = self::minutes($amount);
        $spokenMinutes = self::spokenMinutes($amount);
        $hours = self::hours($amount);
        $spokenHours = self::spokenHours($amount);
        $warnTeam = 'Si no, vamos a avisar a tu equipo de monitoreo.';

        return match ($notice) {
            HosNotice::BreakLead => self::copy(
                "Tu break de 30 min es en {$minutes}",
                "Te quedan {$minutes} para tu break obligatorio de 30 min. Busca dónde parar con seguridad.",
                "Te quedan {$spokenMinutes} para tu descanso obligatorio de media hora. Busca dónde parar con seguridad.",
            ),
            HosNotice::BreakLimit => self::copy(
                'Ya te toca tu break de 30 min',
                'Ya te toca tu break obligatorio de 30 min. Para en cuanto puedas hacerlo con seguridad.',
                'Ya te toca tu descanso obligatorio de media hora. Por favor detente en cuanto puedas hacerlo con seguridad.',
            ),
            HosNotice::BreakInsist => self::copy(
                'Sigues sin tu break de 30 min',
                'Sigues manejando sin tu break de 30 min. Detente ya en un lugar seguro; si no, avisaremos a tu equipo de monitoreo.',
                "Sigues manejando sin tu descanso de media hora. Por favor detente ya en un lugar seguro. {$warnTeam}",
            ),
            HosNotice::DriveLead => self::copy(
                "Tus 11 h de manejo se acaban en {$minutes}",
                "Se te acaban tus 11 h de manejo en {$minutes}. Planea tu parada para tu descanso de 10 h.",
                "Se te acaban tus 11 horas de manejo en {$spokenMinutes}. Planea tu parada para tu descanso de 10 horas.",
            ),
            HosNotice::DriveLimit => self::copy(
                'Se acabaron tus 11 h de manejo',
                'Ya se acabaron tus 11 h de manejo. Para en cuanto puedas con seguridad y toma tu descanso de 10 h.',
                'Ya se acabaron tus 11 horas de manejo. Por favor detente en cuanto puedas hacerlo con seguridad y toma tu descanso de 10 horas.',
            ),
            HosNotice::DriveInsist => self::copy(
                'Sigues manejando sin horas disponibles',
                'Sigues manejando sin horas de manejo disponibles. Detente ya en un lugar seguro; si no, avisaremos a tu equipo de monitoreo.',
                "Sigues manejando y ya no tienes horas de manejo disponibles. Por favor detente ya en un lugar seguro. {$warnTeam}",
            ),
            HosNotice::ShiftLead => self::copy(
                "Tu turno de 14 h termina en {$minutes}",
                "Tu turno de 14 h termina en {$minutes}. Planea tu parada para tu descanso de 10 h.",
                "Tu turno de 14 horas termina en {$spokenMinutes}. Planea tu parada para tu descanso de 10 horas.",
            ),
            HosNotice::ShiftLimit => self::copy(
                'Se acabó tu turno de 14 h',
                'Ya se acabó tu turno de 14 h. Ya no puedes manejar: para con seguridad y toma tu descanso de 10 h.',
                'Ya se acabó tu turno de 14 horas y ya no puedes manejar. Por favor detente en cuanto puedas hacerlo con seguridad y toma tu descanso de 10 horas.',
            ),
            HosNotice::ShiftInsist => self::copy(
                'Sigues fuera de tu turno de 14 h',
                'Sigues trabajando fuera de tu turno de 14 h. Detente ya en un lugar seguro; si no, avisaremos a tu equipo de monitoreo.',
                "Sigues trabajando fuera de tu turno de 14 horas. Por favor detente ya en un lugar seguro. {$warnTeam}",
            ),
            HosNotice::CycleLead => self::copy(
                "Te quedan {$hours} en tu ciclo de 70 h",
                "Te quedan {$hours} en tu ciclo de 70 h. Vas a necesitar tu reinicio de 34 h.",
                "Te quedan {$spokenHours} en tu ciclo de 70 horas. Vas a necesitar tu reinicio de 34 horas.",
            ),
            HosNotice::RestComplete => self::copy(
                'Ya cumpliste tu descanso',
                'Ya cumpliste tu descanso. Cuando estés listo, puedes retomar tu ruta.',
                'Ya cumpliste tu descanso. Cuando estés listo, puedes retomar tu ruta. Buen viaje.',
            ),
            HosNotice::Violation => self::copy(
                'Tus horas de servicio marcan una infracción',
                'Tus horas de servicio marcan una infracción. Para en cuanto puedas con seguridad; ya avisamos a tu equipo de monitoreo.',
                'Tus horas de servicio marcan una infracción. Por favor detente en cuanto puedas hacerlo con seguridad. Ya avisamos a tu equipo de monitoreo.',
            ),
        };
    }

    /** Línea de tiempo del incidente cuando el chofer corrige. */
    public static function corrected(HosSituation $situation): string
    {
        return match ($situation) {
            HosSituation::BreakDue => 'El chofer ya tomó su break de 30 min.',
            HosSituation::DriveLimit, HosSituation::ShiftLimit => 'El chofer ya se detuvo para su descanso de 10 h.',
            HosSituation::CycleLimit => 'El ciclo de 70 h del chofer ya tiene horas disponibles.',
            HosSituation::RestComplete => 'El chofer ya retomó su ruta.',
            HosSituation::Violation => 'Los relojes del chofer ya no marcan infracción.',
        };
    }

    /**
     * @return array{subject: string, body: string, spoken: string}
     */
    private static function copy(string $subject, string $body, string $spoken): array
    {
        return [
            'subject' => $subject,
            'body' => 'SAM: '.$body,
            'spoken' => 'Hola, te llama SAM. '.$spoken,
        ];
    }

    private static function minutes(?int $amount): string
    {
        return max(1, $amount ?? 1).' min';
    }

    private static function spokenMinutes(?int $amount): string
    {
        $n = max(1, $amount ?? 1);

        return $n === 1 ? '1 minuto' : "{$n} minutos";
    }

    private static function hours(?int $amount): string
    {
        return max(1, $amount ?? 1).' h';
    }

    private static function spokenHours(?int $amount): string
    {
        $n = max(1, $amount ?? 1);

        return $n === 1 ? '1 hora' : "{$n} horas";
    }
}
```

- [ ] **Step 5:** Run `php artisan test --compact --filter=HosNoticeCopyTest` → PASS.
- [ ] **Step 6: Commit**

```bash
git add app/Domains/Drivers/Enums/HosNotice.php app/Domains/Drivers/Support/HosNoticeCopy.php tests/Unit/Domains/Drivers/HosNoticeCopyTest.php
git commit -m "feat: textos de los avisos hos al chofer"
```

---

### Task 5: Escalera pura (`HosLadderPlanner`)

**Files:**
- Create: `app/Domains/Drivers/Enums/HosLadderMove.php`, `app/Domains/Drivers/Data/HosLadderDecision.php`, `app/Domains/Drivers/Support/HosLadderPlanner.php`
- Modify: `app/Domains/Drivers/Support/HosMonitoringConfig.php`
- Test: Create `tests/Unit/Domains/Drivers/HosLadderPlannerTest.php`

**Interfaces:**
- Consumes: `HosNotice` (Task 4), `HosMonitoringConfig`, `HosClockReading`.
- Produces:
  - `HosLadderMove`: `hold` (pausa: parado o sin lectura), `wait` (aún no toca), `notify`, `escalate`, `done`.
  - `HosLadderDecision` (readonly): `HosLadderMove $move`, `string $reason`, `int $nextStep`, `?CarbonImmutable $nextNudgeAt`, `?int $step`, `list<string> $channels`, `?HosNotice $notice`, `?int $amount`.
  - `HosLadderPlanner::plan(HosSituation $situation, int $ladderStep, ?CarbonInterface $nextNudgeAt, CarbonInterface $openedAt, bool $escalated, ?HosClockReading $current, HosMonitoringConfig $config, CarbonInterface $now): HosLadderDecision`.
  - Numeración de escalones (`ladder_step` = siguiente a ejecutar = sufijo del `event_key`): límites `0..L-1` avisos previos (`lead_minutes` > 0, mayor primero), `L..L+N-1` escalera; ciclo `0..C-1`; fin de pausa `0..R-1`; violación `0`.
  - `HosMonitoringConfig::ladderSteps(): list<array{after_minutes: int, channels: list<string>, escalate: bool}>`, `informationalChannels(): list<string>`, `leadThresholdsMinutes(): list<int>`, `cycleThresholdsHours(): list<int>`, `restNudgeMinutes(): list<int>`.

- [ ] **Step 1: Test que falla** `tests/Unit/Domains/Drivers/HosLadderPlannerTest.php`:

```php
<?php

namespace Tests\Unit\Domains\Drivers;

use App\Domains\Drivers\Data\HosLadderDecision;
use App\Domains\Drivers\Enums\HosLadderMove;
use App\Domains\Drivers\Enums\HosNotice;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Support\HosLadderPlanner;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Integrations\Data\HosClockReading;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class HosLadderPlannerTest extends TestCase
{
    private const string NOW = '2026-10-04 12:00:00';

    private function config(array $overrides = []): HosMonitoringConfig
    {
        $defaults = require __DIR__.'/../../../../config/hos.php';

        return HosMonitoringConfig::fromArray($overrides, $defaults['defaults']);
    }

    private function reading(?string $status = 'driving', ?int $break = 28800, ?int $drive = 39600, ?int $shift = 50400, ?int $cycle = 252000): HosClockReading
    {
        return new HosClockReading('1', '100', $status, $break, $drive, $shift, $cycle, 0);
    }

    private function plan(
        HosSituation $situation,
        int $step,
        ?HosClockReading $current,
        ?string $nextNudgeAt = null,
        string $at = self::NOW,
        bool $escalated = false,
        string $openedAt = '2026-10-04 11:50:00',
        array $config = [],
    ): HosLadderDecision {
        return (new HosLadderPlanner)->plan(
            $situation,
            $step,
            $nextNudgeAt !== null ? CarbonImmutable::parse($nextNudgeAt) : null,
            CarbonImmutable::parse($openedAt),
            $escalated,
            $current,
            $this->config($config),
            CarbonImmutable::parse($at),
        );
    }

    private function at(?CarbonImmutable $moment): ?string
    {
        return $moment?->format('Y-m-d H:i:s');
    }

    public function test_warnings_before_the_limit_go_to_the_driver_app_once_per_threshold(): void
    {
        $first = $this->plan(HosSituation::BreakDue, 0, $this->reading(break: 26 * 60));
        $this->assertSame(HosLadderMove::Notify, $first->move);
        $this->assertSame('lead_notice', $first->reason);
        $this->assertSame(0, $first->step);
        $this->assertSame(1, $first->nextStep);
        $this->assertSame(['samsara_driver_app'], $first->channels);
        $this->assertSame(HosNotice::BreakLead, $first->notice);
        $this->assertSame(26, $first->amount);

        $this->assertSame(HosLadderMove::Wait, $this->plan(HosSituation::BreakDue, 1, $this->reading(break: 20 * 60))->move);

        $second = $this->plan(HosSituation::BreakDue, 1, $this->reading(break: 14 * 60 + 30));
        $this->assertSame(1, $second->step);
        $this->assertSame(15, $second->amount);
        $this->assertSame(2, $second->nextStep);
    }

    public function test_a_late_first_reading_skips_straight_to_the_most_urgent_warning(): void
    {
        $decision = $this->plan(HosSituation::DriveLimit, 0, $this->reading(drive: 10 * 60));

        $this->assertSame(1, $decision->step);
        $this->assertSame(HosNotice::DriveLead, $decision->notice);
    }

    public function test_reaching_the_limit_walks_the_ladder_then_escalates(): void
    {
        $atLimit = $this->reading(break: 0);

        $app = $this->plan(HosSituation::BreakDue, 2, $atLimit);
        $this->assertSame(HosLadderMove::Notify, $app->move);
        $this->assertSame('ladder_step', $app->reason);
        $this->assertSame(2, $app->step);
        $this->assertSame(['samsara_driver_app'], $app->channels);
        $this->assertSame(HosNotice::BreakLimit, $app->notice);
        $this->assertSame('2026-10-04 12:05:00', $this->at($app->nextNudgeAt));

        $notYet = $this->plan(HosSituation::BreakDue, 3, $atLimit, '2026-10-04 12:05:00', '2026-10-04 12:03:00');
        $this->assertSame(HosLadderMove::Wait, $notYet->move);
        $this->assertSame('not_due', $notYet->reason);

        $whatsapp = $this->plan(HosSituation::BreakDue, 3, $atLimit, '2026-10-04 12:05:00', '2026-10-04 12:05:00');
        $this->assertSame(['samsara_driver_app', 'whatsapp'], $whatsapp->channels);
        $this->assertSame(HosNotice::BreakInsist, $whatsapp->notice);
        $this->assertSame('2026-10-04 12:10:00', $this->at($whatsapp->nextNudgeAt));

        $voice = $this->plan(HosSituation::BreakDue, 4, $atLimit, '2026-10-04 12:10:00', '2026-10-04 12:10:30');
        $this->assertSame(['voice'], $voice->channels);
        $this->assertSame('2026-10-04 12:15:30', $this->at($voice->nextNudgeAt));

        $incident = $this->plan(HosSituation::BreakDue, 5, $atLimit, '2026-10-04 12:15:30', '2026-10-04 12:16:00');
        $this->assertSame(HosLadderMove::Escalate, $incident->move);
        $this->assertSame('ladder_exhausted', $incident->reason);
        $this->assertSame(5, $incident->step);
        $this->assertSame(6, $incident->nextStep);
        $this->assertSame([], $incident->channels);
        $this->assertNull($incident->notice);
        $this->assertNull($incident->nextNudgeAt);

        $this->assertSame(HosLadderMove::Done, $this->plan(HosSituation::BreakDue, 6, $atLimit)->move);
    }

    public function test_reaching_the_limit_before_the_warnings_starts_the_ladder(): void
    {
        $decision = $this->plan(HosSituation::ShiftLimit, 0, $this->reading('onDuty', shift: 0));

        $this->assertSame(2, $decision->step);
        $this->assertSame(HosNotice::ShiftLimit, $decision->notice);
    }

    public function test_the_ladder_pauses_while_the_driver_is_not_working(): void
    {
        $stopped = $this->plan(HosSituation::BreakDue, 3, $this->reading('offDuty', break: 0), '2026-10-04 11:58:00');
        $this->assertSame(HosLadderMove::Hold, $stopped->move);
        $this->assertSame('not_working', $stopped->reason);
        $this->assertSame(3, $stopped->nextStep);
        $this->assertSame('2026-10-04 11:58:00', $this->at($stopped->nextNudgeAt));

        // Vuelve a manejar sin cumplir la pausa: el escalón vencido sale ese minuto.
        $resumed = $this->plan(HosSituation::BreakDue, 3, $this->reading('driving', break: 0), '2026-10-04 11:58:00');
        $this->assertSame(HosLadderMove::Notify, $resumed->move);
        $this->assertSame(3, $resumed->step);

        // La ventana de 14 h corre también trabajando sin manejar; el manejo no.
        $this->assertSame(HosLadderMove::Notify, $this->plan(HosSituation::ShiftLimit, 0, $this->reading('onDuty', shift: 20 * 60))->move);
        $this->assertSame(HosLadderMove::Hold, $this->plan(HosSituation::DriveLimit, 0, $this->reading('onDuty', drive: 20 * 60))->move);
    }

    public function test_without_a_reading_only_a_violation_moves(): void
    {
        $this->assertSame('no_reading', $this->plan(HosSituation::BreakDue, 2, null)->reason);
        $this->assertSame(HosLadderMove::Hold, $this->plan(HosSituation::CycleLimit, 0, null)->move);
        $this->assertSame(HosLadderMove::Escalate, $this->plan(HosSituation::Violation, 0, null)->move);
    }

    public function test_a_violation_tells_the_driver_and_escalates_once(): void
    {
        $decision = $this->plan(HosSituation::Violation, 0, $this->reading());

        $this->assertSame(HosLadderMove::Escalate, $decision->move);
        $this->assertSame('violation', $decision->reason);
        $this->assertSame(['samsara_driver_app'], $decision->channels);
        $this->assertSame(HosNotice::Violation, $decision->notice);
        $this->assertSame(1, $decision->nextStep);
        $this->assertSame(HosLadderMove::Done, $this->plan(HosSituation::Violation, 1, $this->reading())->move);
    }

    public function test_an_escalated_episode_never_moves_again(): void
    {
        $decision = $this->plan(HosSituation::BreakDue, 6, $this->reading(break: 0), escalated: true);

        $this->assertSame(HosLadderMove::Done, $decision->move);
        $this->assertSame('escalated', $decision->reason);
    }

    public function test_cycle_notices_are_one_per_threshold_without_a_ladder(): void
    {
        $five = $this->plan(HosSituation::CycleLimit, 0, $this->reading('offDuty', cycle: 4 * 3600 + 1800));
        $this->assertSame(HosNotice::CycleLead, $five->notice);
        $this->assertSame(5, $five->amount);
        $this->assertSame(0, $five->step);
        $this->assertNull($five->nextNudgeAt);

        $this->assertSame(HosLadderMove::Wait, $this->plan(HosSituation::CycleLimit, 1, $this->reading('offDuty', cycle: 3 * 3600))->move);

        $one = $this->plan(HosSituation::CycleLimit, 1, $this->reading('driving', cycle: 50 * 60));
        $this->assertSame(1, $one->step);
        $this->assertSame(1, $one->amount);

        $this->assertSame(HosLadderMove::Done, $this->plan(HosSituation::CycleLimit, 2, $this->reading(cycle: 10 * 60))->move);
    }

    public function test_rest_complete_reminds_at_15_and_30_minutes(): void
    {
        $opened = '2026-10-04 12:00:00';
        $rested = $this->reading('offDuty');

        $early = $this->plan(HosSituation::RestComplete, 0, $rested, at: '2026-10-04 12:10:00', openedAt: $opened);
        $this->assertSame(HosLadderMove::Wait, $early->move);
        $this->assertSame('2026-10-04 12:15:00', $this->at($early->nextNudgeAt));

        $first = $this->plan(HosSituation::RestComplete, 0, $rested, at: '2026-10-04 12:15:00', openedAt: $opened);
        $this->assertSame(HosLadderMove::Notify, $first->move);
        $this->assertSame(0, $first->step);
        $this->assertSame(HosNotice::RestComplete, $first->notice);
        $this->assertSame('2026-10-04 12:30:00', $this->at($first->nextNudgeAt));

        // Un sondeo perdido no manda dos avisos: sólo el más reciente.
        $missed = $this->plan(HosSituation::RestComplete, 0, $rested, at: '2026-10-04 12:31:00', openedAt: $opened);
        $this->assertSame(1, $missed->step);
        $this->assertSame(2, $missed->nextStep);
        $this->assertNull($missed->nextNudgeAt);

        $this->assertSame(HosLadderMove::Done, $this->plan(HosSituation::RestComplete, 2, $rested, at: '2026-10-04 12:34:00', openedAt: $opened)->move);
    }

    public function test_a_ladder_that_starts_later_waits_for_its_first_step(): void
    {
        $config = ['ladder' => [
            ['after_minutes' => 2, 'channels' => ['samsara_driver_app']],
            ['after_minutes' => 7, 'escalate' => 'incident'],
        ]];

        $scheduled = $this->plan(HosSituation::BreakDue, 0, $this->reading(break: 0), config: $config);
        $this->assertSame(HosLadderMove::Wait, $scheduled->move);
        $this->assertSame('ladder_scheduled', $scheduled->reason);
        $this->assertSame(2, $scheduled->nextStep);
        $this->assertSame('2026-10-04 12:02:00', $this->at($scheduled->nextNudgeAt));

        $due = $this->plan(HosSituation::BreakDue, 2, $this->reading(break: 0), '2026-10-04 12:02:00', '2026-10-04 12:02:00', config: $config);
        $this->assertSame(HosLadderMove::Notify, $due->move);
        $this->assertSame(2, $due->step);
        $this->assertSame('2026-10-04 12:07:00', $this->at($due->nextNudgeAt));
    }

    public function test_unknown_channels_in_the_tenant_ladder_are_ignored(): void
    {
        $config = ['ladder' => [
            ['after_minutes' => 0, 'channels' => ['carrier_pigeon', 'whatsapp']],
            ['after_minutes' => 5, 'channels' => ['carrier_pigeon']],
            ['after_minutes' => 10, 'escalate' => 'incident'],
            'basura',
        ]];

        $decision = $this->plan(HosSituation::BreakDue, 2, $this->reading(break: 0), config: $config);
        $this->assertSame(['whatsapp'], $decision->channels);
        // El escalón sin canales válidos desaparece: el siguiente es el incidente.
        $this->assertSame('2026-10-04 12:10:00', $this->at($decision->nextNudgeAt));
        // Los avisos informativos usan los canales del primer escalón.
        $this->assertSame(['whatsapp'], $this->plan(HosSituation::BreakDue, 0, $this->reading(break: 1200), config: $config)->channels);
    }
}
```

- [ ] **Step 2:** Run `php artisan test --compact --filter=HosLadderPlannerTest` → FAIL.

- [ ] **Step 3: `app/Domains/Drivers/Enums/HosLadderMove.php`**

```php
<?php

namespace App\Domains\Drivers\Enums;

/** What the reminder ladder of an HOS episode does this minute. */
enum HosLadderMove: string
{
    /** Paused: the driver complied (not working) or there is no reading. */
    case Hold = 'hold';
    /** Nothing due yet. */
    case Wait = 'wait';
    case Notify = 'notify';
    case Escalate = 'escalate';
    /** Nothing left to send for this episode. */
    case Done = 'done';
}
```

- [ ] **Step 4: `app/Domains/Drivers/Data/HosLadderDecision.php`**

```php
<?php

namespace App\Domains\Drivers\Data;

use App\Domains\Drivers\Enums\HosLadderMove;
use App\Domains\Drivers\Enums\HosNotice;
use Carbon\CarbonImmutable;

final readonly class HosLadderDecision
{
    /**
     * @param  int  $nextStep  ladder_step to store after this move
     * @param  CarbonImmutable|null  $nextNudgeAt  next_nudge_at to store after this move
     * @param  int|null  $step  step executed now (Notify/Escalate): the event_key suffix
     * @param  list<string>  $channels  ChannelType values for this step's notice
     * @param  int|null  $amount  minutes (or cycle hours) left, for the copy
     */
    public function __construct(
        public HosLadderMove $move,
        public string $reason,
        public int $nextStep,
        public ?CarbonImmutable $nextNudgeAt,
        public ?int $step = null,
        public array $channels = [],
        public ?HosNotice $notice = null,
        public ?int $amount = null,
    ) {}
}
```

- [ ] **Step 5: `HosMonitoringConfig`.** Agregar `use App\Domains\Notifications\Enums\ChannelType;`. En `fromArray()` reemplazar `ladder: array_values((array) $value('ladder')),` por:

```php
            ladder: self::ladderEntries($value('ladder')),
```

y agregar al final de la clase:

```php
    /**
     * @return array<int, array<string, mixed>>
     */
    private static function ladderEntries(mixed $ladder): array
    {
        /** @var array<int, array<string, mixed>> $entries */
        $entries = array_values(array_filter((array) $ladder, fn (mixed $entry): bool => is_array($entry)));

        return $entries;
    }

    /**
     * The ladder, cleaned: unknown channel types dropped, entries left without
     * a channel nor an escalation removed, sorted by `after_minutes`.
     *
     * @return list<array{after_minutes: int, channels: list<string>, escalate: bool}>
     */
    public function ladderSteps(): array
    {
        $steps = [];

        foreach ($this->ladder as $entry) {
            $channels = array_values(array_unique(array_filter(array_map(
                fn (mixed $channel): ?string => is_string($channel) ? ChannelType::tryFrom($channel)?->value : null,
                (array) ($entry['channels'] ?? []),
            ))));
            $escalate = ($entry['escalate'] ?? null) === 'incident';

            if ($channels === [] && ! $escalate) {
                continue;
            }

            $after = $entry['after_minutes'] ?? 0;

            $steps[] = [
                'after_minutes' => is_numeric($after) ? max(0, (int) $after) : 0,
                'channels' => $channels,
                'escalate' => $escalate,
            ];
        }

        usort($steps, fn (array $a, array $b): int => $a['after_minutes'] <=> $b['after_minutes']);

        return $steps;
    }

    /**
     * Channels of informational notices (warnings, cycle, rest complete):
     * the first ladder step that sends something — the free driver app by
     * default.
     *
     * @return list<string>
     */
    public function informationalChannels(): array
    {
        foreach ($this->ladderSteps() as $step) {
            if ($step['channels'] !== []) {
                return $step['channels'];
            }
        }

        return [ChannelType::SamsaraDriverApp->value];
    }

    /**
     * Warnings before a limit, largest first (the 0 is the limit itself).
     *
     * @return list<int>
     */
    public function leadThresholdsMinutes(): array
    {
        $minutes = array_values(array_unique(array_filter($this->leadMinutes, fn (int $value): bool => $value > 0)));
        rsort($minutes);

        return $minutes;
    }

    /**
     * @return list<int> largest first
     */
    public function cycleThresholdsHours(): array
    {
        $hours = array_values(array_unique(array_filter($this->cycleLeadHours, fn (int $value): bool => $value > 0)));
        rsort($hours);

        return $hours;
    }

    /**
     * @return list<int> smallest first
     */
    public function restNudgeMinutes(): array
    {
        $minutes = array_values(array_unique(array_filter($this->restCompleteNudgeMinutes, fn (int $value): bool => $value > 0)));
        sort($minutes);

        return $minutes;
    }
```

- [ ] **Step 6: `app/Domains/Drivers/Support/HosLadderPlanner.php`**

```php
<?php

namespace App\Domains\Drivers\Support;

use App\Domains\Drivers\Data\HosLadderDecision;
use App\Domains\Drivers\Enums\HosDutyStatus;
use App\Domains\Drivers\Enums\HosLadderMove;
use App\Domains\Drivers\Enums\HosNotice;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Integrations\Data\HosClockReading;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Pure reminder-ladder evaluation for one open HOS episode: what to do this
 * minute (spec 2026-10-04 §3.9).
 *
 * `ladder_step` is the next step to execute, and the suffix of its
 * notification `event_key` (`hos:{episode}:{step}`):
 *  - break/drive/shift: steps 0..L-1 are the warnings before the limit
 *    (`lead_minutes` > 0, largest first, only the most urgent crossed one is
 *    sent); reaching the limit starts the ladder at step L (L..L+N-1, one per
 *    `ladder` entry, spaced by their `after_minutes`). The ladder PAUSES while
 *    the driver is not driving (break, drive) or not working (shift) and
 *    resumes where it was if they start again without the clock resetting;
 *  - cycle_limit: one notice per `cycle_lead_hours` threshold, no ladder;
 *  - rest_complete: one notice per `rest_complete_nudge_minutes` after it
 *    opened (the detector expires it);
 *  - violation: a notice plus the incident at once.
 *
 * Informational notices use the first ladder step's channels (the free
 * driver app by default).
 */
class HosLadderPlanner
{
    public function plan(
        HosSituation $situation,
        int $ladderStep,
        ?CarbonInterface $nextNudgeAt,
        CarbonInterface $openedAt,
        bool $escalated,
        ?HosClockReading $current,
        HosMonitoringConfig $config,
        CarbonInterface $now,
    ): HosLadderDecision {
        $now = CarbonImmutable::instance($now);
        $nextNudgeAt = $nextNudgeAt !== null ? CarbonImmutable::instance($nextNudgeAt) : null;

        if ($escalated) {
            return $this->keep(HosLadderMove::Done, 'escalated', $ladderStep, $nextNudgeAt);
        }

        return match ($situation) {
            HosSituation::Violation => $this->violation($ladderStep, $nextNudgeAt, $config),
            HosSituation::CycleLimit => $this->cycle($ladderStep, $nextNudgeAt, $current, $config),
            HosSituation::RestComplete => $this->rest($ladderStep, CarbonImmutable::instance($openedAt), $current, $config, $now),
            HosSituation::BreakDue, HosSituation::DriveLimit, HosSituation::ShiftLimit => $this->limit($situation, $ladderStep, $nextNudgeAt, $current, $config, $now),
        };
    }

    private function violation(int $ladderStep, ?CarbonImmutable $nextNudgeAt, HosMonitoringConfig $config): HosLadderDecision
    {
        if ($ladderStep >= 1) {
            return $this->keep(HosLadderMove::Done, 'violation_raised', $ladderStep, $nextNudgeAt);
        }

        return new HosLadderDecision(
            move: HosLadderMove::Escalate,
            reason: 'violation',
            nextStep: 1,
            nextNudgeAt: null,
            step: 0,
            channels: $config->informationalChannels(),
            notice: HosNotice::Violation,
        );
    }

    private function cycle(int $ladderStep, ?CarbonImmutable $nextNudgeAt, ?HosClockReading $current, HosMonitoringConfig $config): HosLadderDecision
    {
        $thresholds = $config->cycleThresholdsHours();

        if ($ladderStep >= count($thresholds)) {
            return $this->keep(HosLadderMove::Done, 'notices_sent', $ladderStep, $nextNudgeAt);
        }

        if ($current === null) {
            return $this->keep(HosLadderMove::Hold, 'no_reading', $ladderStep, $nextNudgeAt);
        }

        $remaining = $current->cycleRemainingSeconds;

        if ($remaining === null) {
            return $this->keep(HosLadderMove::Wait, 'no_clock', $ladderStep, $nextNudgeAt);
        }

        $due = $this->deepestCrossed($remaining, array_map(fn (int $hours): int => $hours * 3600, $thresholds));

        if ($due === null || $due < $ladderStep) {
            return $this->keep(HosLadderMove::Wait, 'nothing_due', $ladderStep, $nextNudgeAt);
        }

        return new HosLadderDecision(
            move: HosLadderMove::Notify,
            reason: 'cycle_notice',
            nextStep: $due + 1,
            nextNudgeAt: null,
            step: $due,
            channels: $config->informationalChannels(),
            notice: HosNotice::CycleLead,
            amount: max(1, intdiv($remaining + 3599, 3600)),
        );
    }

    private function rest(int $ladderStep, CarbonImmutable $openedAt, ?HosClockReading $current, HosMonitoringConfig $config, CarbonImmutable $now): HosLadderDecision
    {
        $nudges = $config->restNudgeMinutes();

        if ($ladderStep >= count($nudges)) {
            return $this->keep(HosLadderMove::Done, 'notices_sent', $ladderStep, null);
        }

        $pendingAt = $openedAt->addMinutes($nudges[$ladderStep]);

        if ($current === null) {
            return $this->keep(HosLadderMove::Hold, 'no_reading', $ladderStep, $pendingAt);
        }

        $due = null;

        foreach ($nudges as $index => $minutes) {
            if ($now->gte($openedAt->addMinutes($minutes))) {
                $due = $index;
            }
        }

        if ($due === null || $due < $ladderStep) {
            return $this->keep(HosLadderMove::Wait, 'not_due', $ladderStep, $pendingAt);
        }

        $next = $nudges[$due + 1] ?? null;

        return new HosLadderDecision(
            move: HosLadderMove::Notify,
            reason: 'rest_notice',
            nextStep: $due + 1,
            nextNudgeAt: $next !== null ? $openedAt->addMinutes($next) : null,
            step: $due,
            channels: $config->informationalChannels(),
            notice: HosNotice::RestComplete,
        );
    }

    private function limit(HosSituation $situation, int $ladderStep, ?CarbonImmutable $nextNudgeAt, ?HosClockReading $current, HosMonitoringConfig $config, CarbonImmutable $now): HosLadderDecision
    {
        if ($current === null) {
            return $this->keep(HosLadderMove::Hold, 'no_reading', $ladderStep, $nextNudgeAt);
        }

        $status = HosDutyStatus::tryFrom((string) $current->dutyStatus);
        $working = $situation === HosSituation::ShiftLimit
            ? $status !== null && $status->isWorking()
            : $status === HosDutyStatus::Driving;

        if (! $working) {
            // Cumplió (está parado): la escalera se pausa sin perder su lugar.
            return $this->keep(HosLadderMove::Hold, 'not_working', $ladderStep, $nextNudgeAt);
        }

        $remaining = match ($situation) {
            HosSituation::BreakDue => $current->breakRemainingSeconds,
            HosSituation::DriveLimit => $current->driveRemainingSeconds,
            default => $current->shiftRemainingSeconds,
        };

        if ($remaining === null) {
            return $this->keep(HosLadderMove::Wait, 'no_clock', $ladderStep, $nextNudgeAt);
        }

        $leads = $config->leadThresholdsMinutes();
        $leadCount = count($leads);

        if ($remaining > 0) {
            $due = $this->deepestCrossed($remaining, array_map(fn (int $minutes): int => $minutes * 60, $leads));

            if ($due === null || $due < $ladderStep) {
                return $this->keep(HosLadderMove::Wait, 'nothing_due', $ladderStep, $nextNudgeAt);
            }

            return new HosLadderDecision(
                move: HosLadderMove::Notify,
                reason: 'lead_notice',
                nextStep: $due + 1,
                nextNudgeAt: null,
                step: $due,
                channels: $config->informationalChannels(),
                notice: HosNotice::lead($situation),
                amount: max(1, intdiv($remaining + 59, 60)),
            );
        }

        $ladder = $config->ladderSteps();

        if ($ladder === []) {
            return $this->keep(HosLadderMove::Done, 'no_ladder', $ladderStep, $nextNudgeAt);
        }

        $index = max(0, $ladderStep - $leadCount);

        if ($index >= count($ladder)) {
            return $this->keep(HosLadderMove::Done, 'ladder_exhausted', $ladderStep, $nextNudgeAt);
        }

        if ($ladderStep < $leadCount || ($ladderStep === $leadCount && $nextNudgeAt === null)) {
            // Llegó al límite: arranca la escalera (los avisos previos pendientes ya no salen).
            $startsAt = $now->addMinutes($ladder[0]['after_minutes']);

            if ($startsAt->gt($now)) {
                return new HosLadderDecision(HosLadderMove::Wait, 'ladder_scheduled', $leadCount, $startsAt);
            }

            $index = 0;
        } elseif ($nextNudgeAt !== null && $now->lt($nextNudgeAt)) {
            return $this->keep(HosLadderMove::Wait, 'not_due', $ladderStep, $nextNudgeAt);
        }

        $entry = $ladder[$index];
        $step = $leadCount + $index;
        $next = $ladder[$index + 1] ?? null;

        return new HosLadderDecision(
            move: $entry['escalate'] ? HosLadderMove::Escalate : HosLadderMove::Notify,
            reason: $entry['escalate'] ? 'ladder_exhausted' : 'ladder_step',
            nextStep: $step + 1,
            nextNudgeAt: $entry['escalate'] || $next === null
                ? null
                : $now->addMinutes(max(0, $next['after_minutes'] - $entry['after_minutes'])),
            step: $step,
            channels: $entry['channels'],
            notice: $entry['channels'] === [] ? null : HosNotice::limit($situation, insist: $index > 0),
        );
    }

    /**
     * Index of the most urgent threshold already crossed, or null.
     *
     * @param  list<int>  $thresholds  largest first
     */
    private function deepestCrossed(int $remaining, array $thresholds): ?int
    {
        $due = null;

        foreach ($thresholds as $index => $threshold) {
            if ($remaining <= $threshold) {
                $due = $index;
            }
        }

        return $due;
    }

    private function keep(HosLadderMove $move, string $reason, int $ladderStep, ?CarbonImmutable $nextNudgeAt): HosLadderDecision
    {
        return new HosLadderDecision($move, $reason, $ladderStep, $nextNudgeAt);
    }
}
```

- [ ] **Step 7:** Run `php artisan test --compact --filter='HosLadderPlannerTest|ResolveHosMonitoringConfigTest|HosSituationDetectorTest'` → PASS.
- [ ] **Step 8: Commit**

```bash
git add app/Domains/Drivers/Enums/HosLadderMove.php app/Domains/Drivers/Data/HosLadderDecision.php app/Domains/Drivers/Support/HosLadderPlanner.php app/Domains/Drivers/Support/HosMonitoringConfig.php tests/Unit/Domains/Drivers/HosLadderPlannerTest.php
git commit -m "feat: escalera de insistencia hos como planificador puro"
```

---

### Task 6: Incidente HOS por el pipeline (sin IA)

**Files:**
- Modify: `app/Domains/Incidents/Enums/IncidentTypeCode.php`, `app/Domains/Incidents/Actions/CreateIncidentFromEvent.php`, `app/Domains/Automation/Support/TriggerConditionCatalog.php`, `database/seeders/IncidentTypeSeeder.php`, `database/seeders/NormalizationSeeder.php`, `config/ai.php`, `app/Domains/AI/Actions/EvaluateEventWithAI.php`, `app/Domains/Normalization/Actions/NormalizeRawEvent.php`, `app/Domains/TenantConfig/Actions/ApplyDefaultTenantConfig.php`, `docs/SAM/logging.md`, `resources/js/lib/labels.ts`, `resources/js/lib/labels.test.ts`
- Create: `app/Domains/Drivers/Actions/RaiseHosIncident.php`, `database/migrations/2026_10_11_100200_seed_hos_incident_catalog.php`
- Test: Create `tests/Feature/Domains/Drivers/Hos/HosIncidentPipelineTest.php`; Modify `tests/Feature/Domains/TenantConfig/ApplyDefaultTenantConfigTest.php`, `tests/Feature/Domains/Normalization/NormalizationSeederTest.php`

**Interfaces:**
- Produces:
  - `IncidentTypeCode::HosCompliance` (`'hos_compliance'`, prioridad por defecto `high`); `hos_limit_exceeded` y `hos_unattended` → `hos_compliance` en `CreateIncidentFromEvent` (`hos_violation` conserva su resolución actual: cubeta `compliance_violation`).
  - Tipos de evento nuevos `hos_limit_exceeded` y `hos_unattended` (`compliance`, `high`). `hos_violation` y su mapeo Samsara quedan intactos.
  - `ApplyDefaultTenantConfig::HOS_RULE_CODE = 'hos-incident'` (regla `event_type_code in [hos_limit_exceeded, hos_unattended]` → `INCIDENT`, `stop_processing`, prioridad 95). El pack crea 5 reglas.
  - `config('ai.rule_resolved_event_types')` incluye `hos_limit_exceeded`, `hos_unattended` (no `hos_violation`).
  - `NormalizeRawEvent`: evento interno con `internal.driver_id` del mismo team → `normalized_events.driver_id`.
  - `RaiseHosIncident::execute(HosEpisode $episode, ?HosClockReading $current, CarbonInterface $now): array{raised: bool, reason: string|null, raw_event_id: int|null}`; constantes `MONITOR = 'hos_watchdog'`, `LIMIT_EXCEEDED_EVENT_TYPE = 'hos_limit_exceeded'`, `UNATTENDED_EVENT_TYPE = 'hos_unattended'`; `static deduplicationKey(HosEpisode): string` = `hos:{id}`.

- [ ] **Step 1: Test que falla** `tests/Feature/Domains/Drivers/Hos/HosIncidentPipelineTest.php`:

```php
<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Contracts\AI\EventEvaluationAgent;
use App\Domains\AI\Actions\EvaluateEventWithAI;
use App\Domains\AI\Data\AIEvaluationResult;
use App\Domains\AI\Data\AIInputContext;
use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\Assets\Models\Asset;
use App\Domains\Decisions\Actions\EvaluateDecisionRules;
use App\Domains\Decisions\Events\DecisionMade;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Decisions\Models\RuleSet;
use App\Domains\Drivers\Actions\RaiseHosIncident;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Incidents\Actions\CreateIncidentFromEvent;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentType;
use App\Domains\Ingestion\Jobs\ProcessRawEventJob;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Actions\NormalizeRawEvent;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\TenantConfig\Actions\ApplyDefaultTenantConfig;
use App\Models\Team;
use App\Support\TenantContext;
use Database\Seeders\DecisionOutcomeSeeder;
use Database\Seeders\IncidentsSeeder;
use Database\Seeders\NormalizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * El incidente HOS sale por el pipeline existente: evento interno →
 * normalización (con chofer) → evaluación resuelta por regla (sin IA) →
 * regla `hos-incident` → INCIDENT → incidente `hos_compliance`.
 */
class HosIncidentPipelineTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NormalizationSeeder::class);
        $this->seed(DecisionOutcomeSeeder::class);
        $this->seed(IncidentsSeeder::class);
    }

    /**
     * @return array{0: HosEpisode, 1: Driver, 2: Asset}
     */
    private function episode(HosSituation $situation = HosSituation::BreakDue, bool $withAsset = true): array
    {
        $team = Team::factory()->create();
        $driver = Driver::factory()->create(['team_id' => $team->id]);
        $asset = Asset::factory()->create(['team_id' => $team->id]);
        $episode = HosEpisode::factory()->create([
            'team_id' => $team->id, 'driver_id' => $driver->id, 'asset_id' => $withAsset ? $asset->id : null,
            'situation' => $situation, 'opened_at' => now()->subMinutes(20), 'ladder_step' => 5,
            'snapshot_json' => ['break_remaining_s' => 1500],
        ]);

        return [$episode, $driver, $asset];
    }

    private function raise(HosEpisode $episode): array
    {
        return TenantContext::for($episode->team_id, fn () => app(RaiseHosIncident::class)->execute($episode, null, now()->toImmutable()));
    }

    public function test_raising_stores_one_internal_event_per_episode(): void
    {
        Queue::fake();
        [$episode, $driver, $asset] = $this->episode();

        $first = $this->raise($episode);
        $second = $this->raise($episode);

        $this->assertTrue($first['raised']);
        $this->assertFalse($second['raised']);
        $this->assertSame('already_raised', $second['reason']);

        $raw = RawEvent::withoutGlobalScopes()->sole();
        $this->assertSame("hos:{$episode->id}", $raw->deduplication_key);
        $this->assertSame('hos_unattended', $raw->event_type_raw);
        $this->assertSame($episode->team_id, $raw->team_id);
        $this->assertSame(['monitor' => 'hos_watchdog', 'asset_id' => $asset->id, 'driver_id' => $driver->id, 'episode_id' => $episode->id], $raw->payload_json['internal']);
        Queue::assertPushed(ProcessRawEventJob::class, 1);

        $raised = $this->assertSystemLogged('hos.incident.raised', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertSame($raw->id, $raised['result']['raw_event_id']);
        $this->assertSame('already_raised', $this->assertSystemLogged('hos.incident.raised', fn (array $c) => ($c['reason'] ?? null) !== null)['reason']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_violation_raises_hos_limit_exceeded_and_an_episode_without_unit_raises_nothing(): void
    {
        Queue::fake();
        [$violation] = $this->episode(HosSituation::Violation);
        [$orphan] = $this->episode(withAsset: false);

        $this->raise($violation);
        $skipped = $this->raise($orphan);

        $this->assertSame('hos_limit_exceeded', RawEvent::withoutGlobalScopes()->sole()->event_type_raw);
        $this->assertSame('no_asset', $skipped['reason']);
    }

    public function test_the_internal_event_carries_its_driver_but_never_a_foreign_one(): void
    {
        Event::fake([EventNormalized::class]);
        [$episode, $driver, $asset] = $this->episode();
        $foreign = Driver::factory()->create();

        $mine = $this->normalize($episode->team_id, $asset->id, $driver->id);
        $this->assertSame($driver->id, $mine->driver_id);

        $other = $this->normalize($episode->team_id, $asset->id, $foreign->id);
        $this->assertNull($other->driver_id);
        $this->assertSame('cross_tenant_internal_driver', $this->assertSystemLogged('normalization.driver.rejected')['reason']);
        $this->assertNoSensitiveDataLogged();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hosEventTypes(): array
    {
        return ['infracción en los relojes' => ['hos_limit_exceeded'], 'escalera agotada' => ['hos_unattended']];
    }

    #[DataProvider('hosEventTypes')]
    public function test_hos_events_open_an_incident_without_calling_the_ai(string $code): void
    {
        Event::fake([AIEvaluationCompleted::class, DecisionMade::class]);
        $this->app->instance(EventEvaluationAgent::class, new class implements EventEvaluationAgent
        {
            public function evaluate(AIInputContext $context): AIEvaluationResult
            {
                throw new RuntimeException('La IA no debe llamarse para un evento HOS.');
            }
        });

        $team = Team::factory()->create();
        app(ApplyDefaultTenantConfig::class)->execute($team);
        $type = EventType::query()->where('code', $code)->sole();
        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id, 'event_type_id' => $type->id, 'event_category_id' => $type->category_id,
            'event_severity_id' => $type->default_severity_id, 'payload_normalized_json' => ['severity' => 'high'],
        ]);

        $decision = TenantContext::for($team->id, function () use ($event) {
            $evaluation = app(EvaluateEventWithAI::class)->execute($event);
            $this->assertSame(EvaluationMode::RulesOnly, $evaluation->evaluation_mode);
            $this->assertSame(EventClassification::RealEvent, $evaluation->classification);

            return app(EvaluateDecisionRules::class)->execute($evaluation);
        });

        $this->assertSame('INCIDENT', $decision->outcome?->code);
        $this->assertSystemLogged('ai.heuristics.evaluated', fn (array $c) => $c['result']['rule'] === 'rule_resolved_type');
    }

    public function test_a_samsara_hos_violation_still_goes_to_the_ai(): void
    {
        $this->app->instance(EventEvaluationAgent::class, new class implements EventEvaluationAgent
        {
            public function evaluate(AIInputContext $context): AIEvaluationResult
            {
                throw new RuntimeException('Agente espía: prueba que sí se intentó la IA.');
            }
        });

        $team = Team::factory()->create();
        app(ApplyDefaultTenantConfig::class)->execute($team);
        $type = EventType::query()->where('code', 'hos_violation')->sole();
        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id, 'event_type_id' => $type->id, 'event_category_id' => $type->category_id,
            'event_severity_id' => $type->default_severity_id, 'payload_normalized_json' => ['severity' => 'high'],
        ]);

        Event::fake([AIEvaluationCompleted::class, DecisionMade::class, IncidentCreated::class]);
        $evaluation = TenantContext::for($team->id, fn () => app(EvaluateEventWithAI::class)->execute($event));

        // Camino previo: ninguna regla lo resuelve; se intentó el agente (cae a agent_error_fallback).
        $this->assertSame('agent_error_fallback', $evaluation->signals_json['reasoning_steps'][0]);
        $this->assertSystemLogged('ai.heuristics.evaluated', fn (array $c) => $c['result']['rule'] === null);
        $this->assertNotContains('hos_violation', config('ai.rule_resolved_event_types'));
        $this->assertNotContains('hos_violation', ApplyDefaultTenantConfig::HOS_EVENT_TYPES);

        // Sin alias nuevo: el incidente de un hos_violation sigue en la cubeta de cumplimiento.
        $incident = TenantContext::for($team->id, fn () => app(CreateIncidentFromEvent::class)->execute($event));
        $this->assertSame('compliance_violation', $incident->type?->code);
    }

    public function test_both_hos_events_of_a_driver_share_one_hos_compliance_incident(): void
    {
        Event::fake([IncidentCreated::class]);
        [$episode, $driver, $asset] = $this->episode();
        $create = app(CreateIncidentFromEvent::class);

        $incidents = TenantContext::for($episode->team_id, function () use ($create, $episode, $driver, $asset) {
            $make = fn (string $code) => NormalizedEvent::factory()->create([
                'team_id' => $episode->team_id, 'asset_id' => $asset->id, 'driver_id' => $driver->id,
                'event_type_id' => EventType::query()->where('code', $code)->value('id'),
                'event_category_id' => EventType::query()->where('code', $code)->value('category_id'),
            ]);

            return [$create->execute($make('hos_unattended')), $create->execute($make('hos_limit_exceeded'))];
        });

        $this->assertSame('hos_compliance', $incidents[0]->type?->code);
        $this->assertSame($driver->id, $incidents[0]->driver_id);
        // Mismo chofer y tipo dentro de la ventana: el segundo se vincula al primero.
        $this->assertSame($incidents[0]->id, $incidents[1]->id);
    }

    public function test_the_migration_backfills_existing_tenants_once(): void
    {
        $team = Team::factory()->create();
        $ruleSet = RuleSet::factory()->create(['team_id' => $team->id, 'code' => 'custom', 'is_default' => true, 'is_active' => true]);
        $inactive = RuleSet::factory()->create(['team_id' => Team::factory()->create()->id, 'is_default' => true, 'is_active' => false]);
        EventType::query()->whereIn('code', ['hos_limit_exceeded', 'hos_unattended'])->delete();
        IncidentType::query()->where('code', 'hos_compliance')->delete();
        $samsaraViolation = EventType::query()->where('code', 'hos_violation')->sole()->only(['name', 'category_id', 'default_severity_id', 'is_active']);

        $migration = require database_path('migrations/2026_10_11_100200_seed_hos_incident_catalog.php');
        $migration->up();
        $migration->up();

        $this->assertSame('compliance', EventType::query()->where('code', 'hos_limit_exceeded')->sole()->category->code);
        $this->assertSame('compliance', EventType::query()->where('code', 'hos_unattended')->sole()->category->code);
        // El hos_violation de Samsara queda idéntico.
        $this->assertSame($samsaraViolation, EventType::query()->where('code', 'hos_violation')->sole()->only(['name', 'category_id', 'default_severity_id', 'is_active']));
        $this->assertSame(
            IncidentPriority::query()->where('code', 'high')->value('id'),
            IncidentType::query()->where('code', 'hos_compliance')->sole()->default_priority_id,
        );

        $rule = DecisionRule::withoutGlobalScopes()->where('ruleset_id', $ruleSet->id)->sole();
        $this->assertSame('hos-incident', $rule->code);
        $this->assertSame($team->id, $rule->team_id);
        $this->assertTrue($rule->stop_processing);
        $this->assertSame(
            ['all' => [['field' => 'event_type_code', 'operator' => 'in', 'value' => ['hos_limit_exceeded', 'hos_unattended']]]],
            $rule->conditions_json,
        );
        $this->assertSame(0, DecisionRule::withoutGlobalScopes()->where('ruleset_id', $inactive->id)->count());
    }

    private function normalize(int $teamId, int $assetId, int $driverId): NormalizedEvent
    {
        $raw = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $teamId, 'provider_id' => null, 'event_type_raw' => 'hos_unattended',
            'payload_json' => ['eventType' => 'hos_unattended', 'internal' => ['monitor' => 'hos_watchdog', 'asset_id' => $assetId, 'driver_id' => $driverId, 'episode_id' => 1]],
        ]);

        $event = TenantContext::for($teamId, fn () => app(NormalizeRawEvent::class)->execute($raw));
        $this->assertNotNull($event);

        return $event;
    }
}
```

En `ApplyDefaultTenantConfigTest.php`: las dos aserciones `assertSame(4, ...rules_created...)` pasan a `5`, y después de `$this->assertTrue((bool) $rules['suspicious-stop-review']->is_active);` agregar:

```php
        $this->assertTrue((bool) $rules[ApplyDefaultTenantConfig::HOS_RULE_CODE]->stop_processing);
        $this->assertSame(['hos_limit_exceeded', 'hos_unattended'], $rules[ApplyDefaultTenantConfig::HOS_RULE_CODE]->conditions_json['all'][0]['value']);
```

En `NormalizationSeederTest.php`, en `$expectedEventTypes` agregar `'hos_limit_exceeded', 'hos_unattended',` después de `'suspicious_stop',` (`'hos_violation'` sigue en su lugar).

- [ ] **Step 2:** Run `php artisan test --compact --filter='HosIncidentPipelineTest|ApplyDefaultTenantConfigTest|NormalizationSeederTest'` → FAIL.

- [ ] **Step 3: Catálogo.**
  - `IncidentTypeCode.php`: `case HosCompliance = 'hos_compliance';` después de `SuspiciousStop`.
  - `CreateIncidentFromEvent.php`, en `EVENT_TYPE_INCIDENT_ALIASES`:

```php
        'hos_limit_exceeded' => IncidentTypeCode::HosCompliance,
        'hos_unattended' => IncidentTypeCode::HosCompliance,
```

  - `TriggerConditionCatalog.php`, en `incidentTypeOptions()`: `IncidentTypeCode::HosCompliance->value => 'Horas de servicio (HOS)',`.
  - `IncidentTypeSeeder.php`, después de `suspicious_stop`: `['code' => 'hos_compliance', 'name' => 'Horas de servicio (HOS)', 'default_priority_id' => $high?->id],`.
  - `NormalizationSeeder.php`, después de `suspicious_stop` (bloque "Internal monitors"): `['code' => 'hos_limit_exceeded', 'name' => 'Horas de servicio rebasadas', 'category' => 'compliance', 'severity' => 'high'],` y `['code' => 'hos_unattended', 'name' => 'Horas de servicio sin atender', 'category' => 'compliance', 'severity' => 'high'],`. **No tocar** la fila `hos_violation` ni el mapeo `'HosViolation' => 'hos_violation'`.

- [ ] **Step 4: Sin IA.** `config/ai.php`: reemplazar el bloque de `rule_resolved_event_types` por:

```php
    /*
    | Tipos cuyo hecho ya lo establece una regla determinista de SAM: el motor
    | de reglas los resuelve como evento real sin llamar a la IA (no se paga),
    | y a diferencia de `skip_evaluation_event_types` SÍ dejan evaluación, así
    | que el motor de decisiones corre y abre el incidente.
    |
    | - after_hours_movement: se movió fuera del horario que configuró el
    |   cliente; la base y los sitios de cliente ya los descarta la propia
    |   regla (telematics.after_hours_safe_geofence_categories).
    | - hos_limit_exceeded / hos_unattended: eventos PROPIOS del monitoreo HOS
    |   de SAM (los relojes marcan infracción de un chofer inscrito, o no
    |   corrigió tras la escalera de avisos); la regla `hos-incident` abre el
    |   incidente. `hos_violation` (webhook de Samsara) NO va aquí: sigue a la IA.
    */
    'rule_resolved_event_types' => ['after_hours_movement', 'hos_limit_exceeded', 'hos_unattended'],
```

  `EvaluateEventWithAI.php`, en `describeRuleReason()` antes de la línea genérica `str_starts_with($reason, 'rule_resolved_type:')`:

```php
            $reason === 'rule_resolved_type:hos_limit_exceeded' => 'los relojes de horas de servicio del chofer marcan una infracción',
            $reason === 'rule_resolved_type:hos_unattended' => 'el chofer no corrigió su situación de horas de servicio tras los avisos de SAM',
```

- [ ] **Step 5: Chofer en eventos internos.** En `NormalizeRawEvent::createInternalNormalizedEvent()`, después de resolver `$assetId`:

```php
        $driverId = $this->resolveInternalDriverId($rawEvent, $payload);
```

  y en el `updateOrCreate` reemplazar `'driver_id' => null,` por `'driver_id' => $driverId,`. Agregar después de `resolveInternalAssetId()`:

```php
    /**
     * Internal monitors that know the driver (HOS) pass `internal.driver_id`.
     * Honored only when the driver belongs to the raw event's tenant — a
     * forged payload can never bind a foreign driver.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveInternalDriverId(RawEvent $rawEvent, array $payload): ?int
    {
        $driverId = Arr::get($payload, 'internal.driver_id');

        if (! is_numeric($driverId)) {
            return null;
        }

        $belongs = Driver::query()
            ->whereKey((int) $driverId)
            ->where('team_id', $rawEvent->team_id)
            ->exists();

        if ($belongs) {
            return (int) $driverId;
        }

        $rejection = match ($this->classifyRejection(Driver::class, (int) $driverId, $rawEvent->team_id)) {
            'foreign' => 'cross_tenant_internal_driver',
            'trashed' => 'internal_driver_trashed',
            'missing' => 'internal_driver_missing',
        };

        SystemLog::degraded('normalization.driver.rejected', reason: $rejection, input: [
            'raw_event_id' => $rawEvent->id,
        ], calc: ['rejection' => $rejection]);

        return null;
    }
```

- [ ] **Step 6: Regla por defecto.** En `ApplyDefaultTenantConfig.php` agregar las constantes:

```php
    public const string HOS_RULE_CODE = 'hos-incident';

    /** @var list<string> */
    public const array HOS_EVENT_TYPES = ['hos_limit_exceeded', 'hos_unattended'];
```

  y en `seedDecisionRules()`, antes de `return 4;` (que pasa a `return 5;`):

```php
        // Monitoreo HOS: la infracción o la escalera de avisos agotada abre
        // incidente sin IA (el tipo está en ai.rule_resolved_event_types).
        DecisionRule::query()->create([
            'team_id' => $team->id,
            'ruleset_id' => $ruleSet->id,
            'code' => self::HOS_RULE_CODE,
            'name' => 'Horas de servicio → incidente',
            'description' => 'Una infracción de horas de servicio, o un chofer que no corrigió tras los avisos de SAM, abre un incidente para tu equipo de monitoreo.',
            'scope' => RuleScope::EventType,
            'priority' => 95,
            'conditions_json' => [
                'all' => [
                    ['field' => 'event_type_code', 'operator' => 'in', 'value' => self::HOS_EVENT_TYPES],
                ],
            ],
            'outcome_override' => $incident->id,
            'stop_processing' => true,
            'is_active' => true,
        ]);
```

- [ ] **Step 7: Migración** `database/migrations/2026_10_11_100200_seed_hos_incident_catalog.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Monitoreo HOS PR 2 en entornos ya sembrados (producción sólo corre
 * migraciones): los tipos de evento `hos_limit_exceeded` y `hos_unattended`
 * (propios del monitor; `hos_violation` de Samsara no se toca), el tipo de incidente
 * `hos_compliance` y la regla `hos-incident` en el ruleset propio, activo y
 * por defecto de cada tenant (el pack de ApplyDefaultTenantConfig no toca
 * rulesets existentes). Idempotente; en una base sin catálogo (instalación
 * nueva, tests) no hace nada: ahí lo siembran los seeders y el pack.
 * Literales a propósito: la migración no depende del código que cambie.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $category = DB::table('event_categories')->where('code', 'compliance')->value('id');
        $severity = DB::table('event_severities')->where('code', 'high')->value('id');

        $eventTypes = [
            'hos_limit_exceeded' => 'Horas de servicio rebasadas',
            'hos_unattended' => 'Horas de servicio sin atender',
        ];

        foreach ($eventTypes as $code => $name) {
            if ($category === null || DB::table('event_types')->where('code', $code)->exists()) {
                continue;
            }

            DB::table('event_types')->insert([
                'code' => $code,
                'name' => $name,
                'description' => null,
                'category_id' => $category,
                'default_severity_id' => $severity,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $high = DB::table('incident_priorities')->where('code', 'high')->value('id');

        if ($high !== null && ! DB::table('incident_types')->where('code', 'hos_compliance')->exists()) {
            DB::table('incident_types')->insert([
                'code' => 'hos_compliance',
                'name' => 'Horas de servicio (HOS)',
                'description' => null,
                'default_priority_id' => $high,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $incident = DB::table('decision_outcomes')->where('code', 'INCIDENT')->value('id');

        if ($incident === null) {
            return;
        }

        $ruleSets = DB::table('rule_sets')
            ->whereNotNull('team_id')
            ->where('is_default', true)
            ->where('is_active', true)
            ->get(['id', 'team_id']);

        foreach ($ruleSets as $ruleSet) {
            if (DB::table('decision_rules')->where('ruleset_id', $ruleSet->id)->where('code', 'hos-incident')->exists()) {
                continue;
            }

            DB::table('decision_rules')->insert([
                'team_id' => $ruleSet->team_id,
                'ruleset_id' => $ruleSet->id,
                'code' => 'hos-incident',
                'name' => 'Horas de servicio → incidente',
                'description' => 'Una infracción de horas de servicio, o un chofer que no corrigió tras los avisos de SAM, abre un incidente para tu equipo de monitoreo.',
                'scope' => 'event_type',
                'priority' => 95,
                'conditions_json' => json_encode(['all' => [['field' => 'event_type_code', 'operator' => 'in', 'value' => ['hos_limit_exceeded', 'hos_unattended']]]]),
                'outcome_override' => $incident,
                'stop_processing' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Tipos de evento e incidente se quedan: pueden tener filas colgando.
        DB::table('decision_rules')->where('code', 'hos-incident')->delete();
    }
};
```

- [ ] **Step 8: `app/Domains/Drivers/Actions/RaiseHosIncident.php`**

```php
<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Ingestion\Actions\QueueRawEventForProcessing;
use App\Domains\Ingestion\Actions\StoreRawEvent;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Data\HosClockReading;
use App\Support\SystemLog;
use Carbon\CarbonInterface;

/**
 * Hands an HOS episode to the incident pipeline, like the other internal
 * monitors (DetectUnauthorizedStopJob): an `internal_monitor` raw event
 * (`hos_limit_exceeded` when the Samsara clocks show a violation, `hos_unattended` when
 * the reminder ladder ran out) keyed `hos:{episode}`, so it is raised once
 * per episode however many cycles retry. Normalization binds the unit and
 * the driver (same tenant only), the rule engine resolves it without AI
 * (`ai.rule_resolved_event_types`) and the `hos-incident` rule opens the
 * incident.
 *
 * Must run inside the episode's TenantContext.
 */
class RaiseHosIncident
{
    public const string MONITOR = 'hos_watchdog';

    public const string LIMIT_EXCEEDED_EVENT_TYPE = 'hos_limit_exceeded';

    public const string UNATTENDED_EVENT_TYPE = 'hos_unattended';

    public function __construct(
        private readonly StoreRawEvent $storeRawEvent,
        private readonly QueueRawEventForProcessing $queueForProcessing,
    ) {}

    public static function deduplicationKey(HosEpisode $episode): string
    {
        return "hos:{$episode->id}";
    }

    /**
     * @return array{raised: bool, reason: string|null, raw_event_id: int|null}
     */
    public function execute(HosEpisode $episode, ?HosClockReading $current, CarbonInterface $now): array
    {
        $eventType = $episode->situation === HosSituation::Violation
            ? self::LIMIT_EXCEEDED_EVENT_TYPE
            : self::UNATTENDED_EVENT_TYPE;
        $key = self::deduplicationKey($episode);
        $input = ['team_id' => $episode->team_id, 'episode_id' => $episode->id, 'driver_id' => $episode->driver_id];
        $calc = ['situation' => $episode->situation->value, 'event_type_code' => $eventType, 'ladder_step' => $episode->ladder_step];

        // La ruta interna de normalización exige la unidad (internal.asset_id).
        if ($episode->asset_id === null) {
            SystemLog::skipped('hos.incident.raised', reason: 'no_asset', input: $input, calc: $calc);

            return ['raised' => false, 'reason' => 'no_asset', 'raw_event_id' => null];
        }

        $existing = RawEvent::query()
            ->where('team_id', $episode->team_id)
            ->where('deduplication_key', $key)
            ->value('id');

        if (is_numeric($existing)) {
            SystemLog::skipped('hos.incident.raised', reason: 'already_raised', input: $input, calc: $calc, result: ['raw_event_id' => (int) $existing]);

            return ['raised' => false, 'reason' => 'already_raised', 'raw_event_id' => (int) $existing];
        }

        $rawEvent = $this->storeRawEvent->execute(
            payload: [
                'eventType' => $eventType,
                'time' => $now->toIso8601String(),
                'internal' => [
                    'monitor' => self::MONITOR,
                    'asset_id' => $episode->asset_id,
                    'driver_id' => $episode->driver_id,
                    'episode_id' => $episode->id,
                ],
                'hos' => [
                    'situation' => $episode->situation->value,
                    'opened_at' => $episode->opened_at->toIso8601String(),
                    'ladder_step' => $episode->ladder_step,
                    'clocks_at_open' => $episode->snapshot_json,
                    'clocks_now' => $current?->toArray(),
                ],
            ],
            sourceType: EventSourceType::InternalMonitor->value,
            teamId: $episode->team_id,
            providerId: null,
            deduplicationKey: $key,
            eventTypeRaw: $eventType,
        );

        $this->queueForProcessing->execute($rawEvent);

        SystemLog::ok('hos.incident.raised', input: $input + ['asset_id' => $episode->asset_id], calc: $calc, result: [
            'raw_event_id' => $rawEvent->id,
            'job_requested' => true,
        ]);

        return ['raised' => true, 'reason' => null, 'raw_event_id' => $rawEvent->id];
    }
}
```

- [ ] **Step 9: Catálogo de logs.** En `docs/SAM/logging.md`, después de la fila `normalization.asset.rejected`:

```markdown
| `normalization.driver.rejected` | degraded | `cross_tenant_internal_driver`, `internal_driver_trashed`, `internal_driver_missing` | `raw_event_id`; calc `rejection` (= `reason`). Evento interno con `internal.driver_id` (monitoreo HOS) de un chofer que no es del tenant del evento: queda sin chofer. Nunca el id ajeno ni ningún `team_id` |
```

  y en la sección `### HOS (\`hos\`)`, después de `hos.driver.app_disconnected`:

```markdown
| `hos.incident.raised` | ok / skipped | `already_raised` · `no_asset` | `team_id`, `episode_id`, `driver_id` (`asset_id` en ok); calc `situation`, `event_type_code` (`hos_limit_exceeded` = los relojes marcan infracción; `hos_unattended` = escalera agotada), `ladder_step`; result `raw_event_id`, `job_requested`. Evento interno `internal_monitor` con `deduplication_key` `hos:{episodio}`; la regla `hos-incident` lo vuelve incidente sin IA (`ai.rule_resolved_event_types`) |
```

- [ ] **Step 10: Front.** `resources/js/lib/labels.ts`, en `EVENT_TYPE_LABELS`: después de `hos_violation: 'Violación de horas de servicio',` (sin tocarla) agregar `hos_limit_exceeded: 'Horas de servicio rebasadas',` y `hos_unattended: 'Horas de servicio sin atender',`; en el bloque "Tipos de incidente" agregar `hos_compliance: 'Horas de servicio (HOS)',`. En `resources/js/lib/labels.test.ts` agregar:

```ts
import { eventTypeLabel } from '@/lib/labels';

describe('eventTypeLabel', () => {
    it('nombra los eventos e incidentes de horas de servicio', () => {
        expect(eventTypeLabel('hos_limit_exceeded')).toBe('Horas de servicio rebasadas');
        expect(eventTypeLabel('hos_unattended')).toBe('Horas de servicio sin atender');
        expect(eventTypeLabel('hos_violation')).toBe('Violación de horas de servicio');
        expect(eventTypeLabel('hos_compliance')).toBe('Horas de servicio (HOS)');
    });
});
```

  (unir el import con el existente: `import { channelLabel, eventTypeLabel } from '@/lib/labels';`).

- [ ] **Step 11:** Run `php artisan test --compact --filter='HosIncidentPipelineTest|ApplyDefaultTenantConfigTest|NormalizationSeederTest|RuleResolvedEventTypesTest|NormalizeRawEventTest|LoggingConventionsTest'` → PASS. Run `npx vitest run resources/js/lib/labels.test.ts` → PASS.
- [ ] **Step 12: Commit**

```bash
git add app/Domains/Incidents app/Domains/Automation/Support/TriggerConditionCatalog.php app/Domains/AI/Actions/EvaluateEventWithAI.php app/Domains/Normalization/Actions/NormalizeRawEvent.php app/Domains/TenantConfig/Actions/ApplyDefaultTenantConfig.php app/Domains/Drivers/Actions/RaiseHosIncident.php config/ai.php database/seeders database/migrations/2026_10_11_100200_seed_hos_incident_catalog.php docs/SAM/logging.md resources/js/lib tests/Feature/Domains/Drivers/Hos/HosIncidentPipelineTest.php tests/Feature/Domains/TenantConfig/ApplyDefaultTenantConfigTest.php tests/Feature/Domains/Normalization/NormalizationSeederTest.php
git commit -m "feat: incidente hos por el pipeline sin evaluación de ia"
```

---

### Task 7: Avanzar la escalera en cada sondeo

**Files:**
- Create: `app/Domains/Drivers/Actions/SendHosNudge.php`, `app/Domains/Drivers/Actions/LinkHosEpisodeIncident.php`, `app/Domains/Drivers/Actions/AdvanceHosEpisodes.php`
- Modify: `app/Domains/Drivers/Jobs/SyncHosClocksJob.php`, `docs/SAM/logging.md`
- Test: Create `tests/Feature/Domains/Drivers/Hos/AdvanceHosEpisodesTest.php`; Modify `tests/Feature/Domains/Drivers/Hos/SyncHosClocksJobTest.php`

**Interfaces:**
- Consumes: `HosLadderPlanner` (Task 5), `HosNoticeCopy` (Task 4), `RaiseHosIncident` (Task 6), canal/destinatario/fuente (Tasks 2–3), `HosEpisode::attachIncident` (Task 1).
- Produces:
  - `SendHosNudge::execute(TenantIntegration $integration, HosEpisode $episode, HosLadderDecision $decision): Notification`; `NOTIFICATION_TYPE = 'hos.nudge'`; `static eventKey(HosEpisode $episode, int $step): string` = `hos:{id}:{step}`.
  - `LinkHosEpisodeIncident::execute(HosEpisode $episode): ?Incident` (busca por `raw_events.deduplication_key` → `normalized_events.raw_event_id` → `incidents.related_event_id` o `incident_event_links`, todo filtrado por team, y guarda con `attachIncident`).
  - `AdvanceHosEpisodes::execute(TenantIntegration $integration, HosMonitoringConfig $config, CarbonImmutable $now): array{open: int, notified: int, escalated: int, held: int, waiting: int, failed: int}`.
  - `SyncHosClocksJob::handle(..., AdvanceHosEpisodes $advanceEpisodes)` corre la escalera tras `ProcessHosReadings` con el mismo `$now`.

- [ ] **Step 1: Test que falla** `tests/Feature/Domains/Drivers/Hos/AdvanceHosEpisodesTest.php`:

```php
<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Actions\AdvanceHosEpisodes;
use App\Domains\Drivers\Enums\HosDutyStatus;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Incidents\Enums\EventRelationType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentEventLink;
use App\Domains\Ingestion\Jobs\ProcessRawEventJob;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationRecipient;
use Carbon\CarbonImmutable;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class AdvanceHosEpisodesTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, HosTenantFixtures, RefreshDatabase;

    private TenantIntegration $integration;

    private Driver $driver;

    private Asset $asset;

    private \stdClass $twilio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NotificationMeterSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00'));
        $this->hosChannels();
        $this->twilio = $this->fakeTwilio();
        $this->integration = $this->hosIntegration();
        [$this->driver, $this->asset] = $this->hosDriver($this->integration);
    }

    private function episode(HosSituation $situation = HosSituation::BreakDue, array $attributes = []): HosEpisode
    {
        return HosEpisode::factory()->create([
            'team_id' => $this->driver->team_id, 'driver_id' => $this->driver->id, 'asset_id' => $this->asset->id,
            'situation' => $situation, 'opened_at' => now(), ...$attributes,
        ]);
    }

    private function state(string $status = 'driving', int $break = 28800, ?string $disconnectedSince = null): void
    {
        $attributes = [
            'asset_id' => $this->asset->id, 'duty_status' => HosDutyStatus::from($status), 'break_remaining_s' => $break,
            'drive_remaining_s' => 30000, 'shift_remaining_s' => 40000, 'cycle_remaining_s' => 200000, 'violation_s' => 0,
            'app_disconnected_since' => $disconnectedSince, 'observed_at' => now(),
        ];
        $state = HosDriverState::withoutGlobalScopes()->where('driver_id', $this->driver->id)->first();

        if ($state === null) {
            HosDriverState::factory()->create(['team_id' => $this->driver->team_id, 'driver_id' => $this->driver->id, ...$attributes]);

            return;
        }

        $state->forceFill($attributes)->save();
    }

    private function advance(): array
    {
        return app(AdvanceHosEpisodes::class)->execute($this->integration, HosMonitoringConfig::fromArray([], config('hos.defaults')), now()->toImmutable());
    }

    private function appMessages(): int
    {
        return count(Http::recorded(fn ($request) => str_contains($request->url(), '/v1/fleet/messages')));
    }

    public function test_a_warning_before_the_limit_goes_only_to_the_driver_app(): void
    {
        $this->fakeAppMessages();
        $episode = $this->episode();
        $this->state(break: 26 * 60);

        $counts = $this->advance();

        $this->assertSame(1, $counts['notified']);
        $this->assertSame(1, $this->appMessages());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/fleet/messages')
            && $request['driverIds'] === [58072405]
            && str_contains((string) $request['text'], '26 min'));
        $this->assertSame([], $this->twilio->messages);
        $this->assertSame([], $this->twilio->calls);
        $this->assertSame(1, $episode->fresh()->ladder_step);

        $notification = Notification::withoutGlobalScopes()->sole();
        $this->assertSame("hos:{$episode->id}:0", $notification->event_key);
        $this->assertSame(NotificationSourceType::HosEpisode, $notification->source_type);
        $this->assertSame('hos.nudge', $notification->notification_type);
        $recipient = NotificationRecipient::withoutGlobalScopes()->sole();
        $this->assertSame(RecipientType::Driver, $recipient->recipient_type);
        $this->assertSame("samsara:{$this->integration->id}:58072405", $recipient->metadata_json[NotificationRecipient::SAMSARA_APP_ADDRESS_KEY]);

        $sent = $this->assertSystemLogged('hos.nudge.sent');
        $this->assertSame($episode->id, $sent['input']['episode_id']);
        $this->assertSame(['samsara_driver_app'], $sent['calc']['channels']);
        $this->assertSame('break_lead', $sent['calc']['notice']);
        $this->assertNoSensitiveDataLogged();
        $logs = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('Secreto', $logs);
        $this->assertStringNotContainsString('5512345678', $logs);
        $this->assertStringNotContainsString('break obligatorio', $logs);
    }

    public function test_at_the_limit_the_ladder_climbs_to_a_call_and_then_raises_the_incident(): void
    {
        $this->fakeAppMessages();
        Queue::fake([ProcessRawEventJob::class]);
        $episode = $this->episode();
        $this->state(break: 0);

        $this->advance();                                     // 12:00 app
        $this->travel(5)->minutes();
        $this->advance();                                     // 12:05 app + WhatsApp
        $this->travel(5)->minutes();
        $this->advance();                                     // 12:10 llamada
        $this->travel(5)->minutes();
        $counts = $this->advance();                           // 12:15 incidente

        $this->assertSame(2, $this->appMessages());
        $this->assertCount(1, $this->twilio->messages);
        $this->assertSame('whatsapp:+5215512345678', $this->twilio->messages[0]['to']);
        $this->assertCount(1, $this->twilio->calls);
        $this->assertStringContainsString('Hola, te llama SAM.', $this->twilio->calls[0]['params']['twiml']);
        $this->assertSame(1, $counts['escalated']);

        $episode->refresh();
        $this->assertNotNull($episode->escalated_at);
        $this->assertNull($episode->resolved_at);
        $this->assertSame(6, $episode->ladder_step);

        $raw = RawEvent::withoutGlobalScopes()->sole();
        $this->assertSame("hos:{$episode->id}", $raw->deduplication_key);
        $this->assertSame('hos_unattended', $raw->event_type_raw);
        $this->assertSame($this->driver->id, $raw->payload_json['internal']['driver_id']);
        Queue::assertPushed(ProcessRawEventJob::class, 1);

        // Ya escaló: no vuelve a avisar ni a levantar nada.
        $this->travel(5)->minutes();
        $this->advance();
        $this->assertSame(2, $this->appMessages());
        $this->assertSame(1, RawEvent::withoutGlobalScopes()->count());
        $this->assertSystemLogged('hos.incident.raised');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_ladder_pauses_while_the_driver_is_stopped_and_resumes_if_they_drive_again(): void
    {
        $this->fakeAppMessages();
        $episode = $this->episode();
        $this->state(break: 0);
        $this->advance();                                     // 12:00 app (escalón 2)

        $this->travel(6)->minutes();
        $this->state('offDuty', break: 0);
        $this->advance();                                     // 12:06 parado: nada

        $this->assertSame(1, $this->appMessages());
        $this->assertSame([], $this->twilio->messages);
        $held = $this->assertSystemLogged('hos.nudge.skipped', fn (array $c) => $c['reason'] === 'not_working');
        $this->assertSame($episode->id, $held['input']['episode_id']);

        $this->travel(6)->minutes();
        $this->state('driving', break: 0);
        $this->advance();                                     // 12:12 vuelve a manejar: sale el escalón vencido

        $this->assertCount(1, $this->twilio->messages);
        $this->assertSame(4, $episode->fresh()->ladder_step);
    }

    public function test_a_step_is_never_sent_twice(): void
    {
        $this->fakeAppMessages();
        $episode = $this->episode();
        $this->state(break: 26 * 60);

        $this->advance();
        $this->advance();                                     // mismo minuto: nada nuevo
        $this->assertSame(1, $this->appMessages());

        // Un ciclo solapado que leyó el escalón viejo: la clave del aviso lo frena.
        $episode->forceFill(['ladder_step' => 0])->save();
        $this->advance();

        $this->assertSame(1, $this->appMessages());
        $this->assertSame(1, Notification::withoutGlobalScopes()->count());
        $this->assertSame('event_key_exists', $this->assertSystemLogged('notifications.dedup.skipped')['reason']);
        $this->assertSystemLogged('hos.nudge.sent', fn (array $c) => $c['result']['notification_reused'] === true);
        $this->assertSame(1, $episode->fresh()->ladder_step);
    }

    public function test_an_undelivered_app_message_brings_the_next_step_forward(): void
    {
        $this->fakeAppMessages(403);
        $episode = $this->episode();
        $this->state(break: 0);
        $this->advance();                                     // 12:00 escalón 2 por la app: Samsara lo rechaza

        $notification = Notification::withoutGlobalScopes()->where('event_key', "hos:{$episode->id}:2")->sole();
        $this->assertSame(NotificationStatus::Failed, $notification->status);
        $this->assertSame('own_ladder', $this->assertSystemLogged('notifications.escalation_guard.blocked')['reason']);

        $this->travel(1)->minutes();
        $this->advance();                                     // 12:01: no espera a las 12:05

        $this->assertCount(1, $this->twilio->messages);
        $unavailable = $this->assertSystemLogged('hos.nudge.channel_unavailable');
        $this->assertSame('previous_nudge_undelivered', $unavailable['reason']);
        $this->assertSame('failed', $unavailable['calc']['notification_status']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_disconnected_driver_app_holds_the_ladder(): void
    {
        $this->fakeAppMessages();
        $this->episode();
        $this->state(break: 0, disconnectedSince: '2026-10-04 11:59:00');

        $counts = $this->advance();

        $this->assertSame(1, $counts['held']);
        $this->assertSame(0, $this->appMessages());
        $held = $this->assertSystemLogged('hos.nudge.skipped');
        $this->assertSame('no_reading', $held['reason']);
        $this->assertTrue($held['calc']['app_disconnected']);
    }

    public function test_a_violation_raises_the_incident_at_once_and_tells_the_driver(): void
    {
        $this->fakeAppMessages();
        Queue::fake([ProcessRawEventJob::class]);
        $episode = $this->episode(HosSituation::Violation);
        $this->state(break: 0);

        $this->advance();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/fleet/messages') && str_contains((string) $request['text'], 'infracción'));
        $this->assertSame('hos_limit_exceeded', RawEvent::withoutGlobalScopes()->sole()->event_type_raw);
        $this->assertNotNull($episode->fresh()->escalated_at);
    }

    public function test_the_incident_opened_by_the_pipeline_is_linked_to_the_episode(): void
    {
        $episode = $this->episode(attributes: ['escalated_at' => now(), 'ladder_step' => 6]);
        $this->state(break: 0);
        $raw = RawEvent::factory()->create(['team_id' => $episode->team_id, 'deduplication_key' => "hos:{$episode->id}"]);
        $event = NormalizedEvent::factory()->create(['team_id' => $episode->team_id, 'raw_event_id' => $raw->id]);
        $incident = Incident::factory()->create(['team_id' => $episode->team_id, 'related_event_id' => $event->id]);

        $this->advance();

        $this->assertSame($incident->id, $episode->fresh()->incident_id);
        $this->assertSame($incident->id, $this->assertSystemLogged('hos.incident.linked')['result']['incident_id']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_event_folded_into_an_existing_incident_still_links_it(): void
    {
        $episode = $this->episode(attributes: ['escalated_at' => now(), 'ladder_step' => 6]);
        $this->state(break: 0);
        $raw = RawEvent::factory()->create(['team_id' => $episode->team_id, 'deduplication_key' => "hos:{$episode->id}"]);
        $event = NormalizedEvent::factory()->create(['team_id' => $episode->team_id, 'raw_event_id' => $raw->id]);
        $incident = Incident::factory()->create(['team_id' => $episode->team_id]);
        IncidentEventLink::factory()->create(['incident_id' => $incident->id, 'normalized_event_id' => $event->id, 'relation_type' => EventRelationType::SupportingEvent]);

        $this->advance();

        $this->assertSame($incident->id, $episode->fresh()->incident_id);
    }

    public function test_advancing_one_tenant_never_touches_another(): void
    {
        $this->fakeAppMessages();
        $other = $this->hosIntegration();
        [$otherDriver, $otherAsset] = $this->hosDriver($other, '99000001', '999');
        $otherEpisode = HosEpisode::factory()->create(['team_id' => $other->team_id, 'driver_id' => $otherDriver->id, 'asset_id' => $otherAsset->id, 'opened_at' => now()]);
        HosDriverState::factory()->create(['team_id' => $other->team_id, 'driver_id' => $otherDriver->id, 'duty_status' => HosDutyStatus::Driving, 'break_remaining_s' => 0]);

        $this->episode();
        $this->state(break: 0);

        $this->assertNoTenantLeak($this->integration->team_id, fn () => $this->advance());

        $this->assertSame(0, $otherEpisode->fresh()->ladder_step);
        $this->assertSame(0, Notification::withoutGlobalScopes()->where('team_id', $other->team_id)->count());
        $this->assertSame(1, $this->appMessages());
        Http::assertSent(fn ($request) => $request['driverIds'] === [58072405]);
    }
}
```

En `SyncHosClocksJobTest.php`: en `fakeSamsara()` agregar al arreglo de `Http::fake` la entrada `'api.samsara.com/v1/fleet/messages' => Http::response(['data' => []]),`; agregar `use App\Domains\Notifications\Models\NotificationChannel;` y el test:

```php
    public function test_a_poll_sends_the_break_warning_to_the_driver_app(): void
    {
        NotificationChannel::factory()->samsaraDriverApp()->create();
        $integration = $this->tenant();
        $this->link($integration, '58072405', '281');
        $this->fakeSamsara();

        app()->call([new SyncHosClocksJob($integration), 'handle']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/fleet/messages') && $request['driverIds'] === [58072405]);
        $this->assertSame(1, HosEpisode::withoutGlobalScopes()->sole()->ladder_step);
        $this->assertSame(1, $this->assertSystemLogged('hos.ladder.advanced')['result']['notified']);
        $this->assertNoSensitiveDataLogged();
    }
```

- [ ] **Step 2:** Run `php artisan test --compact --filter='AdvanceHosEpisodesTest|SyncHosClocksJobTest'` → FAIL.

- [ ] **Step 3: `app/Domains/Drivers/Actions/SendHosNudge.php`**

```php
<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Data\HosLadderDecision;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverExternalReference;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Drivers\Support\HosNoticeCopy;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Support\SamsaraDriverAppAddress;
use LogicException;

/**
 * One step of the HOS ladder as a notification to the driver: the step's
 * channels forced (`force_channels`), the driver as explicit recipient (his
 * phone for WhatsApp/SMS/voice, his Samsara app address for the app) and
 * the HOS copy (`spoken` for the call). `event_key` = `hos:{episode}:{step}`:
 * SendNotification dedups it, so an overlapping cycle never sends twice.
 *
 * Billing is the existing one: Twilio channels meter and charge cost + 30 %
 * in AttemptDelivery; the Samsara app has no meter.
 *
 * Must run inside the episode's TenantContext.
 */
class SendHosNudge
{
    public const string NOTIFICATION_TYPE = 'hos.nudge';

    public function __construct(
        private readonly SendNotification $sendNotification,
    ) {}

    public static function eventKey(HosEpisode $episode, int $step): string
    {
        return "hos:{$episode->id}:{$step}";
    }

    public function execute(TenantIntegration $integration, HosEpisode $episode, HosLadderDecision $decision): Notification
    {
        if ($decision->step === null || $decision->notice === null) {
            throw new LogicException('An HOS nudge needs a step and a notice.');
        }

        $driver = Driver::query()
            ->where('team_id', $episode->team_id)
            ->findOrFail($episode->driver_id);

        $externalId = DriverExternalReference::query()
            ->where('driver_id', $driver->id)
            ->where('provider_id', $integration->provider_id)
            ->value('external_id');

        $copy = HosNoticeCopy::for($decision->notice, $decision->amount);

        return $this->sendNotification->execute(
            teamId: $episode->team_id,
            notificationType: self::NOTIFICATION_TYPE,
            sourceType: NotificationSourceType::HosEpisode,
            sourceReferenceId: (string) $episode->id,
            priority: NotificationPriority::High,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: self::eventKey($episode, $decision->step),
            payload: [
                'force_channels' => $decision->channels,
                'recipients' => [[
                    'recipient_type' => RecipientType::Driver->value,
                    'address' => 'driver:'.$driver->id,
                    'name' => $driver->full_name,
                    'phone' => $driver->phone,
                    'recipient_reference_id' => (string) $driver->id,
                    'metadata' => is_string($externalId) && $externalId !== ''
                        ? [NotificationRecipient::SAMSARA_APP_ADDRESS_KEY => SamsaraDriverAppAddress::make($integration->id, $externalId)]
                        : [],
                ]],
                'spoken' => $copy['spoken'],
                'hos' => [
                    'episode_id' => $episode->id,
                    'situation' => $episode->situation->value,
                    'step' => $decision->step,
                    'notice' => $decision->notice->value,
                ],
            ],
            subject: $copy['subject'],
            bodyPreview: $copy['body'],
        );
    }
}
```

- [ ] **Step 4: `app/Domains/Drivers/Actions/LinkHosEpisodeIncident.php`**

```php
<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\SystemLog;

/**
 * Finds the incident an escalated episode ended up in and stores it on the
 * episode: its raw event (`hos:{episode}`) → the normalized event → the
 * incident that event opened (`related_event_id`) or was folded into
 * (`incident_event_links`, CreateIncidentFromEvent dedup). Every step is
 * filtered by the episode's team; `attachIncident` checks it again on write.
 * Until the pipeline finishes there is nothing yet: retried every minute.
 *
 * Must run inside the episode's TenantContext.
 */
class LinkHosEpisodeIncident
{
    public function execute(HosEpisode $episode): ?Incident
    {
        $teamId = $episode->team_id;

        if ($episode->incident_id !== null) {
            return Incident::query()->where('team_id', $teamId)->find($episode->incident_id);
        }

        $input = ['team_id' => $teamId, 'episode_id' => $episode->id];

        $rawEventId = RawEvent::query()
            ->where('team_id', $teamId)
            ->where('deduplication_key', RaiseHosIncident::deduplicationKey($episode))
            ->value('id');

        $eventId = is_numeric($rawEventId)
            ? NormalizedEvent::query()->where('team_id', $teamId)->where('raw_event_id', (int) $rawEventId)->value('id')
            : null;

        $incident = is_numeric($eventId)
            ? Incident::query()
                ->where('team_id', $teamId)
                ->where(fn ($query) => $query
                    ->where('related_event_id', (int) $eventId)
                    ->orWhereHas('eventLinks', fn ($links) => $links->where('normalized_event_id', (int) $eventId)))
                ->orderByDesc('id')
                ->first()
            : null;

        if ($incident === null) {
            SystemLog::skipped('hos.incident.linked', reason: 'event_pending', input: $input, calc: [
                'raw_event_present' => $rawEventId !== null,
                'normalized_event_present' => $eventId !== null,
            ], debug: true);

            return null;
        }

        $episode->attachIncident($incident);

        SystemLog::ok('hos.incident.linked', input: $input, result: ['incident_id' => $incident->id]);

        return $incident;
    }
}
```

- [ ] **Step 5: `app/Domains/Drivers/Actions/AdvanceHosEpisodes.php`**

```php
<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Data\HosLadderDecision;
use App\Domains\Drivers\Enums\HosLadderMove;
use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Drivers\Support\HosLadderPlanner;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Integrations\Data\HosClockReading;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Models\Notification;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Runs the reminder ladder of every open HOS episode of the integration's
 * tenant, once per poll, right after ProcessHosReadings (so the clocks are
 * this minute's and corrected episodes are already closed). What each
 * episode does is decided by {@see HosLadderPlanner}; this action sends the
 * step ({@see SendHosNudge}), raises the incident ({@see RaiseHosIncident}),
 * links it back ({@see LinkHosEpisodeIncident}) and stores the new position.
 *
 * Idempotent: steps are notifications keyed `hos:{episode}:{step}` and the
 * escalation a raw event keyed `hos:{episode}`. A step whose notification
 * reached nobody (failed or cancelled) does not wait for its interval: the
 * next step is due right away.
 */
class AdvanceHosEpisodes
{
    public function __construct(
        private readonly HosLadderPlanner $planner,
        private readonly SendHosNudge $sendNudge,
        private readonly RaiseHosIncident $raiseIncident,
        private readonly LinkHosEpisodeIncident $linkIncident,
    ) {}

    /**
     * @return array{open: int, notified: int, escalated: int, held: int, waiting: int, failed: int}
     */
    public function execute(TenantIntegration $integration, HosMonitoringConfig $config, CarbonImmutable $now): array
    {
        $teamId = $integration->team_id;

        return TenantContext::for($teamId, function () use ($integration, $config, $now, $teamId): array {
            $episodes = HosEpisode::query()
                ->where('team_id', $teamId)
                ->open()
                ->orderBy('id')
                ->get();

            $states = HosDriverState::query()
                ->where('team_id', $teamId)
                ->whereIn('driver_id', $episodes->pluck('driver_id')->unique()->values()->all())
                ->get()
                ->keyBy('driver_id');

            $counts = ['open' => $episodes->count(), 'notified' => 0, 'escalated' => 0, 'held' => 0, 'waiting' => 0, 'failed' => 0];

            foreach ($episodes as $episode) {
                $counts[$this->advance($integration, $episode, $states->get($episode->driver_id), $config, $now)]++;
            }

            SystemLog::ok('hos.ladder.advanced', input: [
                'team_id' => $teamId,
                'integration_id' => $integration->id,
            ], result: $counts, debug: $counts['notified'] === 0 && $counts['escalated'] === 0 && $counts['failed'] === 0);

            return $counts;
        });
    }

    /**
     * @return 'notified'|'escalated'|'held'|'waiting'|'failed'
     */
    private function advance(TenantIntegration $integration, HosEpisode $episode, ?HosDriverState $state, HosMonitoringConfig $config, CarbonImmutable $now): string
    {
        if ($episode->escalated_at !== null && $episode->incident_id === null) {
            $this->linkIncident->execute($episode);
        }

        $this->skipAheadAfterUndeliveredNudge($episode, $now);

        $current = $this->currentReading($state);
        $decision = $this->planner->plan(
            $episode->situation,
            $episode->ladder_step,
            $episode->next_nudge_at,
            $episode->opened_at,
            $episode->escalated_at !== null,
            $current,
            $config,
            $now,
        );

        $input = ['team_id' => $episode->team_id, 'episode_id' => $episode->id, 'driver_id' => $episode->driver_id];
        $calc = [
            'situation' => $episode->situation->value,
            'move' => $decision->move->value,
            'ladder_step' => $episode->ladder_step,
            'next_step' => $decision->nextStep,
            'duty_status' => $current?->dutyStatus,
            'app_disconnected' => $state?->app_disconnected_since !== null,
            'state_present' => $state !== null,
        ];

        return match ($decision->move) {
            HosLadderMove::Notify => $this->notify($integration, $episode, $decision, $input, $calc),
            HosLadderMove::Escalate => $this->escalate($integration, $episode, $decision, $current, $now, $input, $calc),
            HosLadderMove::Hold, HosLadderMove::Wait, HosLadderMove::Done => $this->hold($episode, $decision, $input, $calc),
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $calc
     * @return 'held'|'waiting'
     */
    private function hold(HosEpisode $episode, HosLadderDecision $decision, array $input, array $calc): string
    {
        $this->schedule($episode, $decision);

        SystemLog::skipped('hos.nudge.skipped', reason: $decision->reason, input: $input, calc: $calc, debug: true);

        return $decision->move === HosLadderMove::Hold ? 'held' : 'waiting';
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $calc
     * @return 'notified'|'failed'
     */
    private function notify(TenantIntegration $integration, HosEpisode $episode, HosLadderDecision $decision, array $input, array $calc): string
    {
        $calc += ['step' => $decision->step, 'channels' => $decision->channels, 'notice' => $decision->notice?->value];

        try {
            $notification = $this->sendNudge->execute($integration, $episode, $decision);
        } catch (Throwable $e) {
            // El escalón no avanza: el siguiente ciclo lo reintenta con la misma clave.
            SystemLog::failed('hos.nudge.failed', reason: 'dispatch_error', input: $input, calc: $calc, error: $e);

            return 'failed';
        }

        $this->schedule($episode, $decision);

        SystemLog::ok('hos.nudge.sent', input: $input, calc: $calc, result: [
            'notification_id' => $notification->id,
            'notification_reused' => ! $notification->wasRecentlyCreated,
            'next_nudge_at' => $decision->nextNudgeAt?->toIso8601String(),
        ]);

        return 'notified';
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $calc
     * @return 'escalated'|'failed'
     */
    private function escalate(TenantIntegration $integration, HosEpisode $episode, HosLadderDecision $decision, ?HosClockReading $current, CarbonImmutable $now, array $input, array $calc): string
    {
        $calc += ['step' => $decision->step, 'channels' => $decision->channels, 'notice' => $decision->notice?->value];

        try {
            if ($decision->channels !== [] && $decision->notice !== null) {
                $this->sendNudge->execute($integration, $episode, $decision);
            }

            $this->raiseIncident->execute($episode, $current, $now);
        } catch (Throwable $e) {
            SystemLog::failed('hos.nudge.failed', reason: 'escalation_error', input: $input, calc: $calc, error: $e);

            return 'failed';
        }

        // El episodio sigue abierto: su corrección cierra o anota el incidente.
        $episode->forceFill([
            'escalated_at' => $now,
            'ladder_step' => $decision->nextStep,
            'next_nudge_at' => null,
        ])->save();

        return 'escalated';
    }

    /**
     * The previous step reached nobody (Samsara without "Write Messages",
     * driver without phone, suppressed number, WhatsApp outside its window):
     * waiting its interval would only delay the next channel.
     */
    private function skipAheadAfterUndeliveredNudge(HosEpisode $episode, CarbonImmutable $now): void
    {
        if ($episode->ladder_step === 0 || $episode->next_nudge_at === null || $episode->next_nudge_at->lte($now)) {
            return;
        }

        $previousStep = $episode->ladder_step - 1;
        $previous = Notification::query()
            ->where('team_id', $episode->team_id)
            ->where('event_key', SendHosNudge::eventKey($episode, $previousStep))
            ->first();

        if ($previous === null || ! in_array($previous->status, [NotificationStatus::Failed, NotificationStatus::Cancelled], true)) {
            return;
        }

        $episode->forceFill(['next_nudge_at' => $now])->save();

        SystemLog::degraded('hos.nudge.channel_unavailable', reason: 'previous_nudge_undelivered', input: [
            'team_id' => $episode->team_id,
            'episode_id' => $episode->id,
            'driver_id' => $episode->driver_id,
        ], calc: [
            'previous_step' => $previousStep,
            'notification_id' => $previous->id,
            'notification_status' => $previous->status->value,
        ]);
    }

    /**
     * This minute's clocks, or null when unknown: no state yet, or the driver
     * app is disconnected (the stored clocks are frozen). The planner only
     * reads the duty status and the clocks, never the external ids.
     */
    private function currentReading(?HosDriverState $state): ?HosClockReading
    {
        if ($state === null || $state->app_disconnected_since !== null || $state->duty_status === null) {
            return null;
        }

        return $state->toReading('', null);
    }

    private function schedule(HosEpisode $episode, HosLadderDecision $decision): void
    {
        $episode->ladder_step = $decision->nextStep;
        $episode->next_nudge_at = $decision->nextNudgeAt;

        if ($episode->isDirty(['ladder_step', 'next_nudge_at'])) {
            $episode->save();
        }
    }
}
```

- [ ] **Step 6: `SyncHosClocksJob`.** Agregar `use App\Domains\Drivers\Actions\AdvanceHosEpisodes;`; en `handle()` agregar el parámetro `AdvanceHosEpisodes $advanceEpisodes,` después de `ProcessHosReadings $processReadings,` y pasarlo al `use` del closure. Reemplazar:

```php
            $enrollment = $resolveEnrollment->execute($this->integration, $config, $readings, $tags);
            $counts = $processReadings->execute($teamId, $config, $enrollment, now()->toImmutable());
```

por:

```php
            $now = now()->toImmutable();
            $enrollment = $resolveEnrollment->execute($this->integration, $config, $readings, $tags);
            $counts = $processReadings->execute($teamId, $config, $enrollment, $now);
            // La escalera corre con los relojes de este mismo minuto.
            $advanceEpisodes->execute($this->integration, $config, $now);
```

y el docblock de la clase:

```php
/**
 * One HOS poll of one Samsara integration: clocks (+ tags when the tenant
 * enrolls by tag) → enrollment → state and episodes → reminder ladder. A
 * failed provider read discards the WHOLE cycle — a partial listing would
 * read as drivers leaving the set and close their episodes — and the ladder
 * does not move that minute (unknown state). No retries: the next minute
 * polls again.
 */
```

- [ ] **Step 7: Catálogo.** En la sección `### HOS (\`hos\`)` de `docs/SAM/logging.md` cambiar la frase introductoria a "Nunca nombre, teléfono ni texto al chofer: sólo ids, canales, códigos de aviso y números de los relojes." y agregar después de `hos.incident.raised`:

```markdown
| `hos.ladder.advanced` | ok (**debug** si no se avisó, escaló ni falló nada) | — | `team_id`, `integration_id`; result `open`, `notified`, `escalated`, `held` (pausadas: chofer parado o sin lectura), `waiting` (aún no toca o ya terminó), `failed` |
| `hos.nudge.sent` | ok | — | `team_id`, `episode_id`, `driver_id`; calc `situation`, `move`, `ladder_step` (antes), `next_step`, `step` (sufijo del `event_key` `hos:{episodio}:{escalón}`), `channels`, `notice` (`HosNotice`), `duty_status`, `app_disconnected`, `state_present`; result `notification_id`, `notification_reused` (la clave ya existía: ciclo solapado, no se volvió a mandar), `next_nudge_at` |
| `hos.nudge.skipped` | skipped (**debug**) | `no_reading` (sin estado o app desconectada) · `not_working` (pausa: el chofer cumplió y está parado) · `no_clock` · `nothing_due` · `not_due` · `ladder_scheduled` · `ladder_exhausted` · `notices_sent` · `escalated` · `violation_raised` · `no_ladder` | mismo `input`/`calc` que `hos.nudge.sent` |
| `hos.nudge.failed` | failed | `dispatch_error` · `escalation_error` | mismo `input`/`calc`; `error`. El escalón no avanza: el siguiente ciclo lo reintenta con la misma clave |
| `hos.nudge.channel_unavailable` | degraded | `previous_nudge_undelivered` | `team_id`, `episode_id`, `driver_id`; calc `previous_step`, `notification_id`, `notification_status` (`failed`/`cancelled`: app sin permiso *Write Messages*, sin teléfono, número suprimido, WhatsApp fuera de ventana). El siguiente escalón se adelanta a este ciclo |
| `hos.incident.linked` | ok / skipped (**debug**) | `event_pending` | `team_id`, `episode_id`; calc `raw_event_present`, `normalized_event_present`; result `incident_id`. El pipeline (normalización → regla → incidente) aún no termina: se reintenta cada minuto |
```

- [ ] **Step 8:** Run `php artisan test --compact --filter='AdvanceHosEpisodesTest|SyncHosClocksJobTest|ProcessHosReadingsTest|LoggingConventionsTest'` → PASS.
- [ ] **Step 9: Commit**

```bash
git add app/Domains/Drivers/Actions/SendHosNudge.php app/Domains/Drivers/Actions/LinkHosEpisodeIncident.php app/Domains/Drivers/Actions/AdvanceHosEpisodes.php app/Domains/Drivers/Jobs/SyncHosClocksJob.php docs/SAM/logging.md tests/Feature/Domains/Drivers/Hos/AdvanceHosEpisodesTest.php tests/Feature/Domains/Drivers/Hos/SyncHosClocksJobTest.php
git commit -m "feat: escalera de avisos hos al chofer en cada sondeo"
```

---

### Task 8: Cerrar el incidente cuando el chofer corrige

**Files:**
- Create: `app/Domains/Drivers/Actions/SettleHosIncident.php`
- Modify: `app/Domains/Drivers/Actions/ProcessHosReadings.php`, `docs/SAM/logging.md`
- Test: Create `tests/Feature/Domains/Drivers/Hos/SettleHosIncidentTest.php`

**Interfaces:**
- Consumes: `LinkHosEpisodeIncident` (Task 7), `HosNoticeCopy::corrected()` (Task 4), `AppendTimelineEntry`, `CloseIncident` (Incidents).
- Produces: `SettleHosIncident::execute(HosEpisode $episode): string` → `'resolved'|'annotated'|'already_closed'|'incident_not_found'`. Mantiene el incidente abierto si el chofer tiene cualquier otro episodio con `escalated_at` y sin `resolved_at`. `ProcessHosReadings` lo llama, fuera de la transacción, por cada episodio con `escalated_at` que se resuelve `corrected`.

- [ ] **Step 1: Test que falla** `tests/Feature/Domains/Drivers/Hos/SettleHosIncidentTest.php`:

```php
<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Actions\ProcessHosReadings;
use App\Domains\Drivers\Data\HosEnrollment;
use App\Domains\Drivers\Enums\HosEpisodeResolution;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Events\IncidentResolved;
use App\Domains\Incidents\Events\IncidentStatusChanged;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Integrations\Data\HosClockReading;
use App\Domains\Integrations\Models\TenantIntegration;
use Carbon\CarbonImmutable;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Si el chofer corrige antes de que alguien tome el incidente, se cierra
 * solo; si ya alguien lo tomó, sólo se anota en su línea de tiempo.
 */
class SettleHosIncidentTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, HosTenantFixtures, RefreshDatabase;

    private TenantIntegration $integration;

    private Driver $driver;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IncidentsSeeder::class);
        Event::fake([IncidentStatusChanged::class, IncidentResolved::class]);
        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:30:00'));
        $this->integration = $this->hosIntegration();
        [$this->driver, $this->asset] = $this->hosDriver($this->integration);
    }

    private function openIncident(array $attributes = []): Incident
    {
        return Incident::factory()->open()->create(['team_id' => $this->integration->team_id, 'driver_id' => $this->driver->id, ...$attributes]);
    }

    private function escalatedEpisode(?Incident $incident, HosSituation $situation = HosSituation::BreakDue): HosEpisode
    {
        return HosEpisode::factory()->create([
            'team_id' => $this->driver->team_id, 'driver_id' => $this->driver->id, 'asset_id' => $this->asset->id,
            'situation' => $situation, 'opened_at' => now()->subMinutes(30), 'ladder_step' => 6,
            'escalated_at' => now()->subMinutes(10), 'incident_id' => $incident?->id,
        ]);
    }

    /** El chofer tomó su break: el reloj vuelve a 8 h. */
    private function correct(int $drive = 30000): void
    {
        app(ProcessHosReadings::class)->execute(
            $this->integration->team_id,
            HosMonitoringConfig::fromArray([], config('hos.defaults')),
            new HosEnrollment([[
                'reading' => new HosClockReading('58072405', '281', 'offDuty', 28800, $drive, 40000, 200000, 0),
                'driver' => $this->driver,
                'asset' => $this->asset,
            ]], []),
            now()->toImmutable(),
        );
    }

    private function correctionNoted(Incident $incident): bool
    {
        return $incident->timeline()
            ->where('entry_type', TimelineEntryType::ExternallyResolved)
            ->where('title', 'El chofer ya corrigió')
            ->exists();
    }

    public function test_a_correction_before_anyone_takes_it_resolves_the_incident(): void
    {
        $incident = $this->openIncident();
        $episode = $this->escalatedEpisode($incident);

        $this->correct();

        $this->assertSame(HosEpisodeResolution::Corrected, $episode->fresh()->resolution);
        $this->assertSame(IncidentStatusCode::Resolved->value, $incident->fresh('status')->status->code);
        $this->assertTrue($this->correctionNoted($incident));
        $settled = $this->assertSystemLogged('hos.incident.settled');
        $this->assertSame('resolved', $settled['result']['outcome']);
        $this->assertSame($incident->id, $settled['calc']['incident_id']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_incident_someone_already_took_only_gets_a_timeline_entry(): void
    {
        $incident = $this->openIncident(['acknowledged_at' => now()->subMinutes(5)]);
        $this->escalatedEpisode($incident);

        $this->correct();

        $this->assertFalse($incident->fresh('status')->isTerminal());
        $this->assertTrue($this->correctionNoted($incident));
        $settled = $this->assertSystemLogged('hos.incident.settled');
        $this->assertSame('annotated', $settled['result']['outcome']);
        $this->assertTrue($settled['calc']['acknowledged']);
    }

    public function test_a_closed_incident_is_left_alone(): void
    {
        $incident = Incident::factory()->resolved()->create(['team_id' => $this->integration->team_id]);
        $this->escalatedEpisode($incident);

        $this->correct();

        $this->assertFalse($this->correctionNoted($incident));
        $this->assertSame('already_closed', $this->assertSystemLogged('hos.incident.settled')['reason']);
    }

    public function test_an_unknown_incident_is_logged_and_the_poll_goes_on(): void
    {
        $episode = $this->escalatedEpisode(null);

        $this->correct();

        $this->assertSame(HosEpisodeResolution::Corrected, $episode->fresh()->resolution);
        $this->assertSame('incident_not_found', $this->assertSystemLogged('hos.incident.settled')['reason']);
    }

    public function test_an_incident_shared_with_another_open_episode_stays_open(): void
    {
        $incident = $this->openIncident();
        $this->escalatedEpisode($incident);
        // Sin manejo disponible: el de manejo sigue abierto y comparte el incidente.
        $drive = $this->escalatedEpisode($incident, HosSituation::DriveLimit);

        $this->correct(drive: 0);

        $this->assertNull($drive->fresh()->resolved_at);
        $this->assertFalse($incident->fresh('status')->isTerminal());
        $settled = $this->assertSystemLogged('hos.incident.settled');
        $this->assertSame('annotated', $settled['result']['outcome']);
        $this->assertTrue($settled['calc']['other_open_episodes']);
    }

    public function test_another_escalated_episode_not_yet_linked_keeps_the_incident_open(): void
    {
        $incident = $this->openIncident();
        $this->escalatedEpisode($incident);
        // Escaló hace poco: su evento ya se plegó al incidente, pero aún sin incident_id.
        $drive = $this->escalatedEpisode(null, HosSituation::DriveLimit);

        $this->correct(drive: 0);

        $this->assertNull($drive->fresh()->incident_id);
        $this->assertFalse($incident->fresh('status')->isTerminal());
        $settled = $this->assertSystemLogged('hos.incident.settled');
        $this->assertSame('annotated', $settled['result']['outcome']);
        $this->assertTrue($settled['calc']['other_open_episodes']);
    }

    public function test_settling_never_touches_another_tenants_incident(): void
    {
        $other = $this->hosIntegration();
        [$otherDriver, $otherAsset] = $this->hosDriver($other, '99000001', '999');
        $otherIncident = Incident::factory()->open()->create(['team_id' => $other->team_id]);
        HosEpisode::factory()->create([
            'team_id' => $other->team_id, 'driver_id' => $otherDriver->id, 'asset_id' => $otherAsset->id,
            'situation' => HosSituation::BreakDue, 'opened_at' => now()->subMinutes(30), 'escalated_at' => now()->subMinutes(10), 'incident_id' => $otherIncident->id,
        ]);
        $incident = $this->openIncident();
        $this->escalatedEpisode($incident);

        $this->assertNoTenantLeak($this->integration->team_id, fn () => $this->correct());

        $this->assertTrue($incident->fresh('status')->isTerminal());
        $this->assertFalse($otherIncident->fresh('status')->isTerminal());
    }
}
```

- [ ] **Step 2:** Run `php artisan test --compact --filter=SettleHosIncidentTest` → FAIL.

- [ ] **Step 3: `app/Domains/Drivers/Actions/SettleHosIncident.php`**

```php
<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Drivers\Support\HosNoticeCopy;
use App\Domains\Incidents\Actions\AppendTimelineEntry;
use App\Domains\Incidents\Actions\CloseIncident;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\ResolutionCode;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Support\SystemLog;

/**
 * The driver corrected an episode whose ladder already escalated (spec
 * 2026-10-04 §3.11): the incident gets a timeline line and, if nobody
 * acknowledged or claimed it yet and the driver has no other open escalated
 * episode (CreateIncidentFromEvent folds a driver's HOS events into one
 * incident, and the other episode may not be linked yet),
 * it is resolved `resolved_externally` — which also stops its escalation.
 *
 * Must run inside the episode's TenantContext.
 */
class SettleHosIncident
{
    public const string TIMELINE_TITLE = 'El chofer ya corrigió';

    public function __construct(
        private readonly LinkHosEpisodeIncident $linkIncident,
        private readonly AppendTimelineEntry $appendTimelineEntry,
        private readonly CloseIncident $closeIncident,
    ) {}

    /**
     * @return 'resolved'|'annotated'|'already_closed'|'incident_not_found'
     */
    public function execute(HosEpisode $episode): string
    {
        $input = ['team_id' => $episode->team_id, 'episode_id' => $episode->id, 'driver_id' => $episode->driver_id];
        $incident = $this->linkIncident->execute($episode);

        if ($incident === null) {
            // El pipeline aún no lo crea (o se descartó): nada que cerrar.
            SystemLog::skipped('hos.incident.settled', reason: 'incident_not_found', input: $input, calc: [
                'situation' => $episode->situation->value,
            ]);

            return 'incident_not_found';
        }

        $incident->loadMissing('status');

        $calc = [
            'situation' => $episode->situation->value,
            'incident_id' => $incident->id,
            'acknowledged' => $incident->acknowledged_at !== null,
            'claimed' => $incident->claimed_by_user_id !== null,
            'terminal' => $incident->isTerminal(),
            // Cualquier otro episodio escalado y abierto del mismo chofer, esté o
            // no vinculado ya: CreateIncidentFromEvent pudo plegar su evento en
            // este incidente y el vínculo (LinkHosEpisodeIncident) llega después.
            'other_open_episodes' => HosEpisode::query()
                ->where('team_id', $episode->team_id)
                ->where('driver_id', $episode->driver_id)
                ->whereNotNull('escalated_at')
                ->whereNull('resolved_at')
                ->whereKeyNot($episode->id)
                ->exists(),
        ];

        if ($calc['terminal']) {
            SystemLog::skipped('hos.incident.settled', reason: 'already_closed', input: $input, calc: $calc);

            return 'already_closed';
        }

        $this->appendTimelineEntry->execute(
            incident: $incident,
            entryType: TimelineEntryType::ExternallyResolved,
            actorType: TimelineActorType::System,
            title: self::TIMELINE_TITLE,
            description: HosNoticeCopy::corrected($episode->situation),
            payload: ['hos_episode_id' => $episode->id, 'situation' => $episode->situation->value],
        );

        if ($calc['acknowledged'] || $calc['claimed'] || $calc['other_open_episodes']) {
            SystemLog::ok('hos.incident.settled', input: $input, calc: $calc, result: ['outcome' => 'annotated']);

            return 'annotated';
        }

        $this->closeIncident->execute(
            incident: $incident,
            resolutionCode: ResolutionCode::ResolvedExternally,
            summary: 'Se resolvió solo: el chofer corrigió su situación de horas de servicio antes de que alguien tomara el incidente.',
            resolvedByType: IncidentCreatorType::System,
        );

        SystemLog::ok('hos.incident.settled', input: $input, calc: $calc, result: ['outcome' => 'resolved']);

        return 'resolved';
    }
}
```

- [ ] **Step 4: `ProcessHosReadings` final.** Reemplazar el archivo completo por:

```php
<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Data\HosEnrollment;
use App\Domains\Drivers\Enums\HosDutyStatus;
use App\Domains\Drivers\Enums\HosEpisodeResolution;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Drivers\Support\HosSituationDetector;
use App\Domains\Integrations\Data\HosClockReading;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies one successful HOS poll of a tenant: stores each monitored
 * driver's clocks, opens and resolves HOS episodes, closes the episodes of
 * drivers that left the monitored set and, when the driver corrects an
 * episode that already escalated, settles its incident
 * ({@see SettleHosIncident}). The reminders are sent right after, by
 * {@see AdvanceHosEpisodes}.
 *
 * Must only be called with a COMPLETE poll: an empty enrollment closes every
 * open episode as `unenrolled`.
 */
class ProcessHosReadings
{
    public function __construct(
        private readonly HosSituationDetector $detector,
        private readonly SettleHosIncident $settleIncident,
    ) {}

    /**
     * @return array{monitored: int, opened: int, resolved: int, unenrolled: int, app_disconnected: int}
     */
    public function execute(int $teamId, HosMonitoringConfig $config, HosEnrollment $enrollment, CarbonInterface $now): array
    {
        return TenantContext::for($teamId, function () use ($teamId, $config, $enrollment, $now): array {
            // Un chofer por llamada: la primera fila gana.
            $enrolled = [];
            foreach ($enrollment->enrolled as $row) {
                $enrolled[$row['driver']->id] ??= $row;
            }
            $enrolled = array_values($enrolled);

            $counts = ['monitored' => count($enrolled), 'opened' => 0, 'resolved' => 0, 'unenrolled' => 0, 'app_disconnected' => 0];

            $driverIds = array_map(fn (array $row) => $row['driver']->id, $enrolled);

            $states = HosDriverState::query()
                ->where('team_id', $teamId)
                ->whereIn('driver_id', $driverIds)
                ->get()
                ->keyBy('driver_id');

            $openEpisodes = HosEpisode::query()
                ->where('team_id', $teamId)
                ->open()
                ->get()
                ->groupBy('driver_id');

            foreach ($enrolled as ['reading' => $reading, 'driver' => $driver, 'asset' => $asset]) {
                $state = $states->get($driver->id);
                $open = $openEpisodes->get($driver->id, collect())->keyBy(fn (HosEpisode $e) => $e->situation->value);

                $previous = $this->previousReading($state, $reading, $config, $now);

                $detection = $this->detector->detect(
                    $previous,
                    $reading,
                    $config,
                    $open->map(fn (HosEpisode $e) => $e->opened_at)->all(),
                    $now,
                );

                /** @var list<HosEpisode> $toSettle */
                $toSettle = [];

                // Atómico por chofer: o se aplica todo su sondeo o nada.
                $delta = DB::transaction(function () use ($detection, $open, $reading, $teamId, $driver, $asset, $config, $now, $state, &$toSettle): array {
                    $d = ['opened' => 0, 'resolved' => 0, 'app_disconnected' => 0];

                    foreach ($detection->resolve as $situation => $resolution) {
                        $episode = $open->get($situation);

                        if ($episode === null) {
                            continue;
                        }

                        $this->resolve($episode, $resolution, $now, $reading);
                        $d['resolved']++;

                        // Ya había escalado: su incidente se atiende fuera de la transacción.
                        if ($resolution === HosEpisodeResolution::Corrected && $episode->escalated_at !== null) {
                            $toSettle[] = $episode;
                        }
                    }

                    foreach ($detection->open as $situation) {
                        if ($this->open($teamId, $driver->id, $asset->id, $situation, $reading, $config, $now)) {
                            $d['opened']++;
                        }
                    }

                    if ($this->storeState($teamId, $driver->id, $asset->id, $state, $reading, $now)) {
                        $d['app_disconnected']++;
                    }

                    return $d;
                });

                foreach ($delta as $key => $n) {
                    $counts[$key] += $n;
                }

                foreach ($toSettle as $episode) {
                    $this->settle($episode);
                }
            }

            $enrolledIds = array_flip($driverIds);

            foreach ($openEpisodes as $driverId => $episodes) {
                if (isset($enrolledIds[$driverId])) {
                    continue;
                }

                foreach ($episodes as $episode) {
                    $this->resolve($episode, HosEpisodeResolution::Unenrolled, $now, null);
                    $counts['unenrolled']++;
                }
            }

            return $counts;
        });
    }

    /**
     * The stored clocks are only a usable "before" of a transition while
     * they are recent. Up to the rest-complete window (default 35 min) the
     * last real reading is trustworthy: a break served during a short
     * Samsara outage must not be missed. Past it, the natural reset of the
     * clocks after hours away would read as a pause just served (false
     * rest_complete). A disconnected app keeps refreshing `observed_at` with
     * frozen clocks, so its age counts from `app_disconnected_since`, the
     * last moment the clocks were real.
     */
    private function previousReading(?HosDriverState $state, HosClockReading $reading, HosMonitoringConfig $config, CarbonInterface $now): ?HosClockReading
    {
        if ($state === null || $state->observed_at === null) {
            return null;
        }

        $lastRealAt = $state->app_disconnected_since ?? $state->observed_at;

        if ($lastRealAt->lt($now->toImmutable()->subSeconds($config->restCompleteExpireSeconds()))) {
            return null;
        }

        return $state->toReading($reading->externalDriverId, $reading->externalVehicleId);
    }

    private function settle(HosEpisode $episode): void
    {
        try {
            $this->settleIncident->execute($episode);
        } catch (Throwable $e) {
            // Un incidente que no se pudo cerrar no tumba el sondeo: sigue en la bandeja.
            SystemLog::failed('hos.incident.settled', reason: 'exception', input: [
                'team_id' => $episode->team_id,
                'episode_id' => $episode->id,
                'driver_id' => $episode->driver_id,
            ], error: $e);
        }
    }
```

  y a continuación, **sin cambios**, los métodos `open()`, `resolve()` y `storeState()` del PR 1, cerrando la clase con `}`.

- [ ] **Step 5: Catálogo.** En la sección HOS de `docs/SAM/logging.md`, después de `hos.incident.linked`:

```markdown
| `hos.incident.settled` | ok / skipped / failed | `incident_not_found` · `already_closed` · `exception` | `team_id`, `episode_id`, `driver_id`; calc `situation`, `incident_id`, `acknowledged`, `claimed`, `terminal`, `other_open_episodes`; result `outcome` (`resolved`: nadie lo había tomado y se cerró `resolved_externally`; `annotated`: sólo línea de tiempo "El chofer ya corrigió", porque alguien ya lo atendió o el chofer tiene otro episodio escalado abierto, vinculado o no). `failed` no tumba el sondeo |
```

  y en la fila `hos.episode.resolved` reemplazar `` `corrected` = `` por `` `corrected` (si el episodio ya había escalado, ver `hos.incident.settled`) = ``.
- [ ] **Step 6:** Run `php artisan test --compact --filter='SettleHosIncidentTest|ProcessHosReadingsTest|AdvanceHosEpisodesTest|SyncHosClocksJobTest|LoggingConventionsTest'` → PASS.
- [ ] **Step 7: Commit**

```bash
git add app/Domains/Drivers/Actions/SettleHosIncident.php app/Domains/Drivers/Actions/ProcessHosReadings.php docs/SAM/logging.md tests/Feature/Domains/Drivers/Hos/SettleHosIncidentTest.php
git commit -m "feat: cierre del incidente hos cuando el chofer corrige"
```

---

### Task 9: Gates, revisión y PR

- [ ] **Step 1:** `vendor/bin/pint --dirty --format agent`
- [ ] **Step 2:** `composer analyse` → sin errores (no tocar `phpstan-baseline.neon` ni usar `@phpstan-ignore`; arreglar la causa). Puntos probables: `value()` mixto en drivers/acciones (ya con `is_numeric`), el `match` exhaustivo de `HosNotice::lead/limit` y `ChannelDriverRegistry`, el `@var` de `ladderEntries`.
- [ ] **Step 3:** `php artisan test --compact` (suite completa) → PASS. Si `public/hot` existe (dev server), apartarlo durante la corrida (SSR, ver memoria `prepush_ssr_hot_file`).
- [ ] **Step 4:** Front: `npm run types:check && npm run lint:check && npm run format:check && npm test` → PASS.
- [ ] **Step 5:** Revisión de aislamiento: lanzar el agente `tenant-isolation-reviewer` sobre la rama (foco: `SamsaraDriverAppNotificationDriver`, `LinkHosEpisodeIncident`, `SettleHosIncident`, `NormalizeRawEvent::resolveInternalDriverId`, la migración de reglas) y atender lo que reporte con commits nuevos.
- [ ] **Step 6:** Validación en vivo (dev, team 5 `sam-pruebas`, **sólo con permiso explícito del usuario y un chofer de prueba**, spec §2): `sail artisan migrate`; `docker compose restart horizon scheduler`; confirmar que `POST /v1/fleet/messages` llega a la app del chofer de prueba (si responde 401/403, pedir al cliente el scope *Write Messages* — la escalera brinca sola a WhatsApp). Revisar `hos.nudge.sent`, `hos.ladder.advanced` y, si se fuerza un episodio en el límite, el incidente `hos_compliance` y su cierre al corregir.
- [ ] **Step 7:** Push y PR (`feat: monitoreo hos — pr 2 insistencia al chofer`). Cuerpo: resumen, desviaciones del spec (sección arriba, en especial #1 episodio abierto tras escalar y #2 IA evitada por `rule_resolved_event_types`), pruebas corridas, resultado de la validación en vivo, aviso de que la UI llega en el PR 3. **Sin** `Co-Authored-By` ni banner "Generated with Claude Code" (regla del repo). Esperar CI (`gh pr checks --watch`) y arreglar lo rojo con commits nuevos.

---

## Siguientes planes

- **PR 3 — UI:** sección `?seccion=hos` (tags, unidades, situaciones, umbrales y escalera con validación: escalones crecientes, mínimo 2 min, `after_minutes` del primero = 0), vista previa del conjunto, panel HOS de chofer (estado, barras, episodio activo, historial de avisos con canal y resultado desde `notifications` con fuente `hos_episode`) y de flota; broadcast al cerrar cada sondeo.
