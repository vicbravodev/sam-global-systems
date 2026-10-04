# SAM como PWA con avisos push (Web Push / VAPID)

**Fecha:** 2026-10-03
**Estado:** diseño aprobado, pendiente de implementar

## Problema

Hoy un monitorista solo se entera de una alerta si tiene SAM abierto: `critical-incident-alert.tsx` usa `new Notification(...)` y un pitido con `AudioContext` mientras la pestaña vive, y `NotificationPushedBroadcast` pinta un toast. Con la app cerrada o el teléfono en el bolsillo no llega nada salvo SMS/WhatsApp/llamada (pagados).

Existe un canal `ChannelType::Push` con driver FCM (`PushNotificationDriver` + `FcmMessenger`, `kreait/firebase-php`, tabla `user_push_tokens`) que **nunca ha entregado nada**:

1. Espera un `user_id` numérico en `address`, pero los flujos de incidentes ponen el email (`IncidentSupervisors::recipientFor`, `ResolveRecipients`).
2. Nada registra tokens (no hay ruta ni controlador).
3. No hay canal de plataforma `push` activo, así que `ProvidedChannels` nunca lo ofrece.

Aun así `push` ya figura en `TenantNotificationPolicy::defaults()->criticalChannels` y en los pasos por defecto de la escalera (`ApplyDefaultTenantConfig`). Arreglar el canal lo mete en el flujo existente sin tocar las reglas de enrutamiento.

## Objetivo

Que la alerta llegue al teléfono (Android y iPhone con SAM instalado) y al escritorio aunque SAM esté cerrado, gratis, como **complemento** de la llamada de Twilio — no su reemplazo. Web Push no puede sonar en bucle ni saltarse el modo silencio; lo único que rompe el silencio sigue siendo la llamada.

**Éxito:** un incidente crítico produce una notificación del sistema en el dispositivo suscrito del primer respondiente con la app cerrada; tocarla abre el incidente; cada nivel de la escalera vuelve a sonar/vibrar; una suscripción muerta se poda sola; nada de esto frena SMS/llamada.

## Qué llega como push

| Aviso (tipo) | Origen | Cómo entra push |
|---|---|---|
| Incidente crítico/alto creado → primeros respondientes | `NotifyOnIncidentCreated::routeToFirstResponders` | ya usa canales críticos de la política (incluyen `push`) |
| Escalera de SLA `incident.sla_breached` (cada nivel/intento) | `CheckIncidentAcknowledgementJob` → `NotifyEscalationLevel` | crítico/alto usa los canales del paso (default incluye `push`) |
| `incident.emergency_confirmed`, `incident.verification_no_answer`, `incident.verification_unavailable`, `incident.priority_raised` | escalera / `SendNotification` | ídem (críticos) |
| `incident.assigned.on_call` | `AssignOnCallOnIncidentCreated` | hoy `force_channels: [web]` → pasa a `[web, push]` |
| **Nuevo** `incident.assigned` (asignación manual o automática a otra persona) | listener nuevo de `IncidentAssigned` | `force_channels: [web, push]`, sólo al asignado, nunca al actor |

**No** llega como push: cambios de estado, incidentes medios/bajos (se quedan en web), salud de integraciones, billing, automatizaciones, riesgo de chofer. Mandar de más entrena a la gente a silenciar el canal.

Las preferencias por usuario (`notification_preferences`) siguen aplicando en lo no crítico: quien no quiera push de asignaciones lo quita en "Mis avisos". Lo crítico ignora preferencias, como hoy.

## Enfoque: Web Push estándar (VAPID), se retira FCM

- Dependencia nueva `minishlink/web-push` (PHP). Sin SDK JS de terceros: el navegador usa `PushManager.subscribe` nativo.
- Se retiran `FcmMessenger`, `FcmSendReport`, `UserPushToken` (+ factory, relación en `User`), la clave `firebase_credentials` de `EncryptedChannelConfigCast` y `kreait/firebase-php` de `composer.json`. Estamos pre-producción: diseño limpio sobre compatibilidad. Si algún día hay app nativa, FCM/APNs vuelve como canal propio.
- La tabla `user_push_tokens` se elimina (migración que la dropea) y se crea `push_subscriptions`.

## Backend

### Datos

`push_subscriptions`:

| columna | notas |
|---|---|
| `id` | |
| `team_id` | FK teams, cascade; `BelongsToTenant` |
| `user_id` | FK users, cascade |
| `endpoint` | text |
| `endpoint_hash` | char(64) sha256 del endpoint, **único** (un dispositivo = una fila) |
| `public_key` | `p256dh` |
| `auth_token` | `auth` (cifrado con cast `encrypted`) |
| `content_encoding` | default `aes128gcm` |
| `device_label` | derivado del user-agent en servidor ("iPhone", "Android", "Mac · Chrome"…), nullable |
| `last_used_at` | última entrega aceptada |
| timestamps | |

Índice `(team_id, user_id)`. La suscripción vale para **un usuario dentro de un team**: si el mismo dispositivo se suscribe estando en otro team, el upsert por `endpoint_hash` lo mueve a ese team (el dispositivo sigue al team en el que el usuario lo activó por última vez). Un usuario con dos teams elige en cuál recibe en ese dispositivo — filtrar de más.

### Endpoints (web, sesión)

Dentro del grupo de settings autenticado con team actual:

```
POST   /settings/push-subscriptions   → push-subscriptions.store   (upsert por endpoint_hash)
DELETE /settings/push-subscriptions   → push-subscriptions.destroy (por endpoint en el cuerpo)
```

- FormRequest valida `endpoint` (url https), `keys.p256dh`, `keys.auth`, `content_encoding` opcional.
- `store` asigna `team_id = currentTeam`, `user_id = auth`. `destroy` sólo borra filas del propio usuario (y team actual).
- La clave pública VAPID se comparte al frontend como prop Inertia compartida (`webPush.publicKey`, null si no está configurada).

### Dirección del canal

`NotificationRecipient::addressForChannel(ChannelType::Push)` devuelve `recipient_reference_id` cuando `recipient_type === User`; para cualquier otro tipo, `null` (→ el despacho lo salta con `no_address`, que ya existe). Así no hay que tocar cada productor de destinatarios.

### Driver

`PushNotificationDriver::send(RenderedNotification, NotificationChannel)`:

1. `address` numérico → `user_id`; si no, `failure('push_invalid_address')`.
2. Resuelve el `team_id` desde la fila `NotificationDelivery` (`deliveryId`, lo fija `AttemptDelivery`); sin entrega → failure.
3. Verifica que el usuario siga siendo miembro del team; si no → failure `push_not_member`.
4. Carga `PushSubscription` de ese usuario **en ese team**. Sin suscripciones → `failure('push_no_subscriptions')` (dispara el fallback existente si la política lo tiene).
5. Construye el payload JSON (≤ 4 KB): `title` (subject), `body`, `url` (show del incidente con slug del team si `variables.incident_id`, si no `notifications.show`), `tag` (`incident-{id}` o `notification-{id}`), `critical` (bool, prioridad crítica), `renotify: true`.
6. Envía a todas vía contrato `App\Contracts\Notifications\WebPushSender` (implementación `MinishlinkWebPushSender` en `app/Infrastructure/`), con opciones `urgency: high` + `TTL: 3600` si es crítico, `urgency: normal` + `TTL: 86400` si no.
7. Respuestas 404/410 → borra esa suscripción (log `notifications.push.subscription_pruned`). Éxitos → `last_used_at = now()`.
8. ≥1 éxito → `DeliveryResult::success`; 0 → `failure` con conteos (retry/fallback existentes).

Sin VAPID configurado (`config('webpush.vapid.*')` vacío) → `failure('push_not_configured')` y log degradado; nunca excepción.

### Canal de plataforma

Una migración de datos asegura una fila `NotificationChannel` activa `channel_type = push` (sin `config_json`; las llaves viven en env). Con eso `ProvidedChannels` lo ofrece en "Mis avisos" y en la política del tenant.

### Push no frena lo pagado

`DeliveryEscalationGuard` sólo cuenta SMS/WhatsApp (aceptados) y voz (contestada) como "contacto que interrumpe", y el cooldown pagado sólo mira canales pagados. Push no entra en ninguno: se cubre con un test que lo fija.

### Configuración

`config/webpush.php` con `vapid.subject` (`VAPID_SUBJECT`, p. ej. `mailto:soporte@…`), `vapid.public_key` (`VAPID_PUBLIC_KEY`), `vapid.private_key` (`VAPID_PRIVATE_KEY`). Comando `sam:vapid-keys` imprime un par nuevo para pegar en `.env`. `.env.example` lleva las tres claves vacías.

### Listener de asignación

`NotifyOnIncidentAssigned` (escucha `IncidentAssigned`, `ShouldQueue` + `afterCommit` porque `AssignIncident` despacha el evento dentro de su transacción; cola `notifications`):

- Omite (log `skipped` con reason): `assigned_to_type` ≠ `User` (`not_user`), asignado = quien asignó (`self_assigned`), incidente terminal (`terminal`), asignación ya reemplazada (`stale`), y `role === 'on_call'` (`on_call_already_notified`: `AssignOnCallOnIncidentCreated` ya avisa `incident.assigned.on_call`).
- `SendNotification` tipo `incident.assigned`, prioridad = la del incidente mapeada (crítica → Critical, resto → High), `force_channels: [web, push]`, destinatario sólo el asignado, `event_key = incident_assigned:{assignment_id}`.
- Copy nuevo `IncidentNoticeCopy::assigned`.
- Se añade `incident.assigned` a los tipos base de "Mis avisos".

### Logging (`docs/SAM/logging.md`)

| código | cuándo |
|---|---|
| `notifications.push_subscription.registered` | alta/actualización (calc: `created`, `moved_team`) |
| `notifications.push_subscription.removed` | baja por el usuario |
| `notifications.push.sent` | ≥1 éxito (calc: `subscriptions`, `successes`, `failures`) |
| `notifications.push.failed` | 0 éxitos, reason `no_subscriptions` / `not_member` / `invalid_address` / `all_failed` / `not_configured` |
| `notifications.push.subscription_pruned` | 404/410 del servicio de push |
| `incidents.assignment.notified` / `incidents.assignment.skipped` | listener de asignación |

Nunca se loguea el endpoint, las llaves ni el cuerpo.

## Frontend / PWA

- `public/manifest.webmanifest`: `name` "SAM", `short_name` "SAM", `start_url` "/", `display` "standalone", `theme_color`/`background_color` de la marca, íconos 192, 512 y 512 maskable (PNG generados del logo en `public/icons/`).
- `app.blade.php`: `<link rel="manifest">`, `<meta name="theme-color">`, `apple-mobile-web-app-capable`.
- `public/sw.js` escrito a mano, **sin caché offline** (evita servir builds viejos):
  - `push`: parsea JSON, `showNotification(title, { body, tag, renotify, icon, badge, data: { url }, requireInteraction: critical, vibrate: critical ? [400,200,400,200,800] : [200] })`.
  - `notificationclick`: cierra, enfoca una ventana existente de SAM y navega a `url`, o abre una nueva.
  - `pushsubscriptionchange`: re-suscribe con la misma clave y hace POST al endpoint (best-effort).
- `resources/js/lib/web-push.ts`: soporte (`serviceWorker` + `PushManager` + `Notification`), detección iOS no instalado, `registerServiceWorker()`, `subscribe()`, `unsubscribe()`, estado actual. Llama las rutas vía Wayfinder.
- El service worker se registra al cargar la app autenticada (sin pedir permiso).
- "Mis avisos" (`settings/notifications.tsx`): tarjeta **"Avisos en este dispositivo"** con estado (no soportado / instala SAM primero (iOS) / bloqueado en el navegador / desactivado / activado) y botón Activar/Desactivar. El permiso sólo se pide desde ese clic.
- Banner descartable en `ops-layout`: "Recibe las alertas en este dispositivo aunque SAM esté cerrado" → Activar, sólo si hay soporte, VAPID configurado y `Notification.permission === 'default'`. Descartado se recuerda en `localStorage`.

## Fuera de alcance (v1)

- Botón "Tomar" dentro de la notificación (requiere URL firmada; siguiente paso natural).
- Caché offline / funcionamiento sin red.
- Contador de no leídos en la campana.
- App nativa / alertas críticas que rompen el silencio.

## Tests

- **Driver** (fake de `WebPushSender`): éxito con 2 suscripciones; 410 poda; todas fallan → failure; sin suscripciones; usuario ya no miembro; suscripción de otro team del mismo usuario **no** recibe (fuga); sin VAPID → failure degradado; payload con URL del incidente, tag y `critical`.
- **Endpoints**: store crea y hace upsert (mismo endpoint → misma fila, mueve team); validación; destroy sólo borra lo propio; otro usuario/otro team no puede borrar (fuga, `AssertsTenantIsolation`); invitado → 401/redirect.
- **Dirección**: `addressForChannel(Push)` = id para `User`, null para contacto externo.
- **Integración**: incidente crítico creado con política default → entrega `push` creada para el primer respondiente con suscripción; push entregado no bloquea retry de SMS (guard).
- **Listener de asignación**: avisa al asignado por web+push; no avisa al actor; no duplica on_call; terminal → skip; fuga de tenant.
- **SystemLog**: `assertSystemLogged` en cada código y `assertNoSensitiveDataLogged`.
- **Frontend**: `types:check`, `lint:check`, `format:check`, `build`; verificación manual en navegador (SW registrado, suscripción guardada, push real recibida en local con llaves VAPID de dev).
