# Spec 5 — Alta automática del webhook de Samsara

Fecha: 2026-10-04 · Rama: `feat/alta-automatica-webhook-samsara` · Índice: [00](2026-10-04-samsara-alertas-00-indice.md) · Depende de: spec 1

## Problema

Para recibir pánicos en segundos, hoy el cliente:

1. crea a mano un webhook en Samsara apuntando a la URL que muestra SAM,
2. lo agrega como acción a su alerta de pánico,
3. copia la Secret Key de Samsara y la pega en SAM (`ConfigureWebhookSecret`).

El paso 3 ya rompió la recepción una vez (Secret Key mal pegada → firmas
rechazadas, pánicos sólo por el poll de respaldo). Y los pasos 1–2 dependen de
que alguien del cliente sepa hacerlo.

La API de Samsara permite hacer los tres pasos con el token del cliente:

- `POST /webhooks` (scope **Write Webhooks**) devuelve `id` y `secretKey`.
- `POST /alerts/configurations` (scope **Write Alerts**) crea una alerta con
  disparador Panic Button (`triggerTypeId` 1034) y acción Webhook
  (`actionTypeId` 4, `actionParams.webhooks.webhookIds`).
- `DELETE /webhooks/{id}` y `DELETE /alerts/configurations` para limpiar.

## Objetivo

Que el cliente sólo pegue su API key. SAM crea el webhook, guarda la Secret Key y
crea **su propia** alerta "SAM – Botón de pánico". Si el token no tiene los
permisos de escritura, SAM lo dice claro en Integraciones y se queda el flujo
manual de hoy.

## Decisiones

- **Alerta propia, no modificar las del cliente.** No se hace `PATCH` sobre
  alertas que el cliente ya tiene: es invasivo y al desconectar no sabríamos qué
  restaurar. La alerta de SAM se identifica por su id guardado.
- **Si el cliente ya tiene su propio flujo manual funcionando** (el endpoint ya
  recibió webhooks válidos), no se aprovisiona: se respeta `manual`.
- **Doble alerta de pánico.** Si el cliente tiene su propia alerta de pánico
  (sin webhook a SAM) y además se crea la de SAM, un mismo botón genera dos
  `AlertIncident` con configuraciones distintas. Sólo la de SAM llega por webhook;
  el poll de respaldo consulta todas las de pánico y la deduplicación de incidentes
  (mismo tipo, misma unidad, ventana de `incidents.duplicate_window_minutes`) los
  enlaza en un solo incidente. Se cubre con un test.
- **URL pública obligatoria.** La URL del webhook se arma con
  `services.samsara.webhook_base_url` (env `SAMSARA_WEBHOOK_BASE_URL`, cae a
  `app.url`). Si no es `https://`, no se aprovisiona (`reason: public_url_not_https`):
  Samsara no podría entregar.

## Diseño

### Esquema

Migración sobre `webhook_endpoints`:

| Columna | Tipo | Uso |
|---|---|---|
| `setup_mode` | string(16), default `manual` | `manual` \| `automatic` |
| `setup_status` | string(32) nullable | `pending` \| `provisioned` \| `missing_permissions` \| `failed` \| `skipped` |
| `setup_error` | string(255) nullable | Motivo corto y seguro (nunca el cuerpo de Samsara) |
| `provider_webhook_id` | string(64) nullable | id del webhook en Samsara |
| `provider_alert_configuration_id` | string(64) nullable | id de la alerta "SAM – Botón de pánico" |
| `provisioned_at` | timestamp nullable | |
| `previous_secret` | text nullable, cast `encrypted` | Secret anterior durante una rotación |
| `previous_secret_expires_at` | timestamp nullable | Fin de la gracia de la rotación |

### Adaptador

`ProviderAdapter` (y `SamsaraAdapter`, `NullProviderAdapter`) gana:

- `createWebhook(TenantIntegration, string $name, string $url): array{id: string, secret: string}`
- `deleteWebhook(TenantIntegration, string $webhookId): void` (404 = ya no existe, ok)
- `createPanicAlertConfiguration(TenantIntegration, string $name, string $webhookId): string` (id)
- `deleteAlertConfiguration(TenantIntegration, string $configurationId): void`

Los errores 401/403 lanzan `ProviderUnauthorized` (ya existe); el resto,
`ProviderRequestFailedException`. El `secretKey` nunca se registra.

### Acción `ProvisionSamsaraWebhook`

`App\Domains\Integrations\Actions\ProvisionSamsaraWebhook::execute(TenantIntegration)`,
dentro de `TenantContext::for($integration->team_id)`:

1. Salta (`skipped`) si la integración no es Samsara, no está activa, el endpoint
   ya recibió webhooks válidos (`manual` funcionando) o ya está `provisioned`.
2. Salta si la URL pública no es `https`.
3. `createWebhook` con nombre `SAM – {nombre del team}` (recortado a 255).
4. `createPanicAlertConfiguration` (`scope.all = true`, `isEnabled = true`,
   `payloadType = enriched`).
5. Guarda en el endpoint `secret`, `secret_configured_at`, ids, `setup_mode =
   automatic`, `setup_status = provisioned`, `provisioned_at`. Audita
   `integration.webhook.provisioned` (como `ConfigureWebhookSecret`, sin el valor).
6. Si falla el paso 4 tras crear el webhook, borra el webhook (compensación) y
   marca `failed`.
7. 401/403 en cualquier paso → `missing_permissions` (el flujo manual sigue
   disponible tal cual). Otros errores → `failed` con reintento del job.

Job `ProvisionSamsaraWebhookJob` (cola `sync`, `ShouldBeUnique` por integración,
`tries = 3`, backoff 60/300/900). Se despacha:

- desde el listener de `IntegrationConnected` (alta),
- al actualizar las credenciales de una integración con `setup_status` en
  `missing_permissions` o `failed` (el cliente generó un token con permisos),
- desde el botón "Configurar automáticamente" de Integraciones.

### Limpieza al desconectar

`IntegrationController::destroy` borra la integración (y su token). Antes de
borrar, si el endpoint está `provisioned`, despacha
`DeprovisionSamsaraWebhookJob`, que implementa `ShouldBeEncrypted` y lleva el
token y los dos ids (la fila ya no existirá cuando corra). Borra la alerta y luego
el webhook; un 404 cuenta como hecho. Si falla tras sus reintentos, lo registra
(`degraded`) y no bloquea nada: lo que queda en Samsara es un webhook que apunta a
una URL que responde 404.

### Rotación

`RotateSamsaraWebhookSecret` (botón "Rotar llave", sólo `automatic`):

1. Crea un webhook nuevo.
2. Cambia la acción de la alerta de SAM al webhook nuevo
   (`PATCH /alerts/configurations`).
3. Guarda el secret nuevo; el anterior pasa a `previous_secret` con
   `previous_secret_expires_at = now + 10 min`.
4. Borra el webhook viejo.

`HandleWebhook` valida con el secret vigente y, si falla y la gracia no venció,
con `previous_secret` (log `secret_variant: previous`). Un job diario limpia
`previous_secret` vencidos.

### Integraciones (UI)

`presentWebhook` añade `setupMode`, `setupStatus`, `setupError`, `provisionedAt`.
En la tarjeta de Samsara:

- `provisioned` → "Pánicos configurados automáticamente" + botón "Rotar llave".
- `missing_permissions` → aviso: "Tu API key no tiene permiso para crear webhooks
  y alertas. Genera una con *Write Webhooks* y *Write Alerts*, o configura el
  webhook a mano." El formulario manual de la Secret Key sigue visible.
- `failed` → aviso con "Reintentar".
- `manual` → como hoy.

Rutas nuevas (web y api, misma Policy `update`):
`POST integrations/{integration}/webhook/provision`,
`POST integrations/{integration}/webhook/rotate`. Wayfinder regenerado.

## Logging

`integrations.webhook.provisioned` (ok), `integrations.webhook.provision_skipped`
(skipped: `not_samsara`, `inactive`, `already_receiving`, `already_provisioned`,
`public_url_not_https`), `integrations.webhook.provision_failed` (failed:
`missing_permissions`, `provider_error`, `compensated`),
`integrations.webhook.deprovisioned` (ok / degraded),
`integrations.webhook.rotated` (ok), `webhook.signature.verified` gana
`secret_variant: previous`. Todos en `docs/SAM/logging.md`. Nunca token, secret ni
URL del webhook: sólo ids internos (`integration_id`, `webhook_endpoint_id`).

## Tests

- Alta feliz con `Http::fake`: crea webhook y alerta, guarda el secret cifrado y
  los ids, audita, despacha desde `IntegrationConnected`.
- 403 en `POST /webhooks` → `missing_permissions`, sin secret, flujo manual intacto.
- Falla la alerta → se borra el webhook creado (compensación) → `failed`.
- Endpoint que ya recibe webhooks válidos → `skipped already_receiving`.
- URL no https → `skipped`.
- Desconectar con `provisioned` → job cifrado borra alerta y webhook; 404 = ok.
- Rotación: webhooks firmados con el secret viejo pasan durante la gracia y se
  rechazan después.
- Dos `AlertIncident` del mismo pánico (alerta del cliente por poll + alerta de
  SAM por webhook) → un incidente.
- **Fuga**: el job de alta de la integración del tenant A nunca escribe en el
  endpoint del tenant B; las rutas `provision`/`rotate` de una integración ajena
  responden 404/403.
- Página de Integraciones: presenta los estados nuevos; nunca el secret.
- `assertSystemLogged` + `assertNoSensitiveDataLogged` (token y secret).

## Fuera de alcance

- Crear webhooks para otros eventos (`GeofenceEntry`, etc.).
- Migrar a `automatic` a un cliente que ya funciona en `manual` (se puede hacer con
  el botón cuando quiera).
