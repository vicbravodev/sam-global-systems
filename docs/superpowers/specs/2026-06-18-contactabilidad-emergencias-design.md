# Diseño — Contactabilidad de emergencias: teléfonos de users y drivers + protocolo de contacto bajo coacción

- **Fecha:** 2026-06-18
- **Estado:** Aprobado (brainstorming) — pendiente de plan de implementación
- **Dominios afectados:** Notifications, Incidents, Drivers, Access, TenantConfig, Decisions
- **Tipo:** Feature (datos + flujo) — sin dependencias nuevas, additive-only

---

## 1. Contexto y problema

La auditoría del subsistema de telefonía (esta sesión) encontró tres huecos que dejan la respuesta a emergencias a medio cablear:

1. **`User` no tiene teléfono.** [ResolveRecipients](../../../app/Domains/Notifications/Actions/ResolveRecipients.php) usa `user->email` como `address` para **todos** los canales. Cuando un step de escalación fuerza `voice`/`sms` sin contactos explícitos, el destino es un email → Twilio no puede marcarlo. La escalera telefónica al equipo **no funciona** out-of-the-box.
2. **El teléfono del conductor existe en el esquema (`DriverContact`) pero está huérfano** — ningún flujo de emergencia lo lee, y no hay un "número del conductor" como tal en el modelo `Driver`.
3. **No existe ningún canal de contacto pensado para el conductor** durante una emergencia, ni con resguardo ante coacción.

El subsistema que **sí** existe y se reutiliza: verificación por voz a la **central** ([StartIncidentCallVerification](../../../app/Domains/Incidents/Actions/StartIncidentCallVerification.php), DTMF 1/2), on-call ([TenantScheduleProfile](../../../app/Domains/TenantConfig/Models/TenantScheduleProfile.php) + [AssignOnCallOnIncidentCreated](../../../app/Domains/Incidents/Listeners/AssignOnCallOnIncidentCreated.php)), y la escalera por SLA ([CheckIncidentAcknowledgementJob](../../../app/Domains/Incidents/Jobs/CheckIncidentAcknowledgementJob.php)), todos con Twilio Voice/SMS/WhatsApp reales.

## 2. Decisiones tomadas (brainstorming)

| # | Decisión | Valor |
|---|----------|-------|
| D1 | Canales de contacto del conductor | SMS, voz y WhatsApp (Twilio). Sin app/push de conductor. |
| D2 | Quién contacta al conductor en un pánico **activo** | Decide el operador (humano-en-el-loop). El sistema **no** lo contacta por su cuenta. |
| D3 | Rigor del número | Users: E.164 + verificación OTP. Drivers: E.164 con validación de formato, sin OTP. |
| D4 | Escalación por SLA | Cadena on-call → supervisores → equipo, con canales crecientes (push/web → SMS/WhatsApp → voz). |
| D5 | Modelo de datos | Campos directos en los modelos (enfoque A). `DriverContact` se conserva para terceros. |

## 3. Diseño

### 3.1 Modelo de datos (additive)

| Tabla | Columna nueva | Tipo | Notas |
|-------|---------------|------|-------|
| `users` | `phone` | `string` nullable | Línea del operador (E.164). Sirve para SMS/voz/WhatsApp. |
| `users` | `phone_verified_at` | `timestamp` nullable | Sello del OTP confirmado. |
| `drivers` | `phone` | `string` nullable | **Línea del propio conductor** (E.164). Fuente de verdad para contactarlo. |

- Un solo número por persona cubre los tres canales Twilio (WhatsApp usa el prefijo `whatsapp:` sobre el mismo E.164).
- `DriverContact` no se modifica: sigue siendo para contactos de terceros (emergencia, supervisor, familiar). El operador puede escalar a un familiar desde ahí.
- El código OTP **no** necesita tabla: vive en caché (Valkey) con TTL ~10 min; al validarlo se sella `users.phone_verified_at`.
- Se *extiende* `User` con columnas (permitido); no se reemplaza. Migraciones additive-only.

### 3.2 Resolución de destino por canal

El bug de fondo es que `RecipientDescriptor` lleva un único `address`. Cambio:

- `RecipientDescriptor` pasa a llevar `email` y `phone`, con un método `addressFor(ChannelType): ?string` (email → correo; `sms`/`voice`/`whatsapp` → phone).
- [DispatchNotification](../../../app/Domains/Notifications/Actions/DispatchNotification.php), al iterar los canales seleccionados por destinatario, usa `addressFor($channel->channel_type)`. Si devuelve `null` (p. ej. user sin `phone`), **omite ese canal y registra el delivery como `skipped` con motivo** — nunca pasa un email a Twilio como teléfono.
- `ResolveRecipients::buildFromTeamMembers` incluye `phone` del user además de `email`.
- `ResolveRecipients::buildExplicit` (contactos externos): un contacto E.164 alimenta `phone`; uno con `@` alimenta `email`. `addressFor` resuelve según el canal.

Con esto, el fan-out por voz/SMS de la escalera funciona y el on-call alcanza al operador por teléfono, no solo email.

### 3.3 Protocolo de contacto al conductor (anti-coacción)

**Principios:**

1. **Mientras un pánico esté activo (incidente `panic_emergency` no terminal), el sistema no inicia ningún contacto automático con la línea del conductor.** El contacto automático solo aplica a incidentes no-pánico (avisos operativos normales usan `drivers.phone` sin restricción). La frontera es el **estado del incidente**, no el tipo de mensaje.
2. **Contactar al conductor en un pánico es una acción del operador**, desde la consola del incidente, con guiones predefinidos.
3. **Asumir lo peor:** Samsara manda "Panic Button" genérico (no distingue coacción de avería); por tanto todo pánico se trata como posible coacción.

**El mensaje discreto:** no puede sonar a alerta de pánico (delataría al conductor ante un agresor que vea el teléfono). Es un texto de apariencia rutinaria con código encubierto — p. ej. una "confirmación de ruta" donde una respuesta significa *estoy bien* y otra es la señal de duress. Texto y códigos **configurables por tenant** con default conservador.

**Asimetría del duress code:**

| Respuesta del conductor | Interpretación | Acción |
|--------------------------|----------------|--------|
| Código seguro (explícito) | Baja sospecha | El operador puede resolver / el motor baja prioridad |
| Código de peligro (duress) | Coacción confirmada | Escala a protocolo de autoridades + **el sistema no vuelve a contactar al conductor** |
| Silencio (sin respuesta en X min) | No concluyente | Escala (autoridades / contacto de emergencia de `DriverContact`). **El silencio nunca es "todo bien".** |
| Código no reconocido / ambiguo | No concluyente | Igual que silencio |

**Caso límite — sin operador 24/7:** se respeta D2 (decide el operador) como default, con un *opt-in* por tenant: si nadie reconoce el pánico en N minutos y la política está activa, el sistema envía automáticamente el check-in discreto con duress code. **Apagado por defecto.** Es la única excepción al "no automático", y es explícita.

**Modelo y componentes:**

- `IncidentDriverContact` (tenant-scoped, `BelongsToTenant`): `incident_id`, `team_id`, `channel` (sms/whatsapp/voice), `mode` (discreet/voice), `to_phone`, `status` (pending/sent/answered/no_response/failed), `outcome` (safe/duress/no_response), `keyword_or_digit`, `sent_at`, `responded_at`, `metadata_json`.
- `ContactDriverFromIncident` (acción, dominio Incidents): operador-triggered; valida que el operador tiene permiso; coloca SMS/WhatsApp (Twilio) o llamada de voz con TwiML propio; registra el `IncidentDriverContact`; metered vía `RecordUsageEvent`.
- `DriverContactTwiml` (soporte): guion de voz al conductor (distinto del de la central).
- `IncidentDriverContactController` → `POST /{current_team}/incidents/{incident}/contact-driver` (policy de operador).
- `TwilioInboundController` → webhook entrante de Twilio (SMS/WhatsApp del conductor + gather de voz), valida firma, asocia la respuesta al `IncidentDriverContact` pendiente por número, mapea código seguro/peligro.
- Fallback opt-in: integrado en `CheckIncidentAcknowledgementJob` (o job hermano) condicionado al setting; dispara `ContactDriverFromIncident` en modo discreto.
- El resultado se publica como hecho `driver_contact_outcome` en [DecisionFactsBuilder](../../../app/Domains/Decisions/Support/DecisionFactsBuilder.php) para re-evaluación/decisión, y se registra en el timeline del incidente + `AuditLog`.

**Settings de tenant (TenantConfig):** `driver_contact.discreet_template`, `driver_contact.safe_code`, `driver_contact.duress_code`, `driver_contact.auto_discreet_on_unack` (bool, default false), `driver_contact.auto_discreet_delay_minutes`.

### 3.4 Cadena de escalación a users

Reusa [CheckIncidentAcknowledgementJob](../../../app/Domains/Incidents/Jobs/CheckIncidentAcknowledgementJob.php) (niveles, delays y reintentos ya existen). Cambios:

- Cada nivel de `TenantEscalationConfig.steps_json` admite: `role` (resuelve a los users con ese rol vía Access), `user_ids` explícitos, o `contacts` externos (E.164/email), además de `channels`, `delay_minutes`, `attempts`, `retry_minutes`. Generaliza el `contacts: []` actual.
- Nueva acción `ResolveEscalationRecipients`: expande `role`/`user_ids`/`contacts` a `RecipientDescriptor`s con `phone` para canales telefónicos.
- Escalera por defecto ([ApplyDefaultTenantConfig](../../../app/Domains/TenantConfig/Actions/ApplyDefaultTenantConfig.php)):
  - **N0 (inmediato):** on-call (ya asignado) → push/web.
  - **N1 (+min sin ack):** supervisores (por rol) → SMS/WhatsApp.
  - **N2 (+min):** fan-out al equipo → voz + SMS.
  - **N3 (opcional):** contactos externos del centro de monitoreo.
- "Reconocer" (`acknowledged_at`) detiene la escalera. El ack llega desde la consola o desde el DTMF "1" de la verificación a la central (ya existe).

### 3.5 OTP, validación y facturación

- **OTP (solo users):** `SendUserPhoneOtp` (genera código 6 dígitos en Valkey con TTL, envía por SMS, rate-limit) y `VerifyUserPhoneOtp` (valida, sella `phone_verified_at`). Endpoints bajo el perfil del usuario; UI mínima (campo + "verificar"). Los users en la cadena de escalación (on-call/supervisores) requieren `phone` verificado; el resto, opcional.
- **Validación E.164:** Form Requests en alta/edición de driver y user. Drivers: formato obligatorio, sin OTP.
- **Facturación:** `event_key` idempotente por OTP, por contacto al conductor (sms/whatsapp/voice) y por notificación de escalación de pago, reusando los meters de notificaciones/llamadas.

## 4. Componentes (responsabilidad única)

**Nuevos:**
- `database/migrations/2026_06_18_*_add_phone_to_users_table.php`, `*_add_phone_to_drivers_table.php`, `*_create_incident_driver_contacts_table.php`
- `app/Domains/Incidents/Models/IncidentDriverContact.php`
- `app/Domains/Incidents/Enums/{DriverContactChannel,DriverContactMode,DriverContactStatus,DriverContactOutcome}.php`
- `app/Domains/Incidents/Actions/ContactDriverFromIncident.php`, `ResolveEscalationRecipients.php`
- `app/Domains/Incidents/Support/DriverContactTwiml.php`
- `app/Http/Controllers/Incidents/IncidentDriverContactController.php`
- `app/Http/Controllers/Webhooks/TwilioInboundController.php`
- `app/Domains/Access/Actions/{SendUserPhoneOtp,VerifyUserPhoneOtp}.php`
- `app/Http/Controllers/Profile/PhoneVerificationController.php`
- Form Requests E.164 para driver/user; UI mínima (perfil user + alta driver)

**Modificados:**
- `app/Models/User.php` (fillable `phone`, cast `phone_verified_at`)
- `app/Domains/Drivers/Models/Driver.php` (fillable `phone`)
- `app/Domains/Notifications/Data/RecipientDescriptor.php` (`email`+`phone`+`addressFor`)
- `app/Domains/Notifications/Actions/ResolveRecipients.php`, `DispatchNotification.php`
- `app/Domains/Incidents/Jobs/CheckIncidentAcknowledgementJob.php` (resolver por rol/user_ids/contacts)
- `app/Domains/TenantConfig/Actions/ApplyDefaultTenantConfig.php` (steps default + settings `driver_contact.*`)
- `app/Domains/Decisions/Support/DecisionFactsBuilder.php` (hecho `driver_contact_outcome`)
- `routes/api.php` (contact-driver, inbound Twilio, OTP)

## 5. Manejo de errores y casos límite

- Número inválido o Twilio falla → se registra el delivery como `failed` con motivo; el flujo no se rompe.
- Sin canal voz/SMS configurado → se omite ese canal y se registra (no se manda email a Twilio).
- Conductor responde código no reconocido → no concluyente (no baja la alarma).
- Respuesta del conductor tras cierre del incidente → se ignora y se registra.
- Webhooks inbound duplicados → idempotentes por SID/incident.
- Coacción: el mensaje nunca revela que es una alerta; el silencio nunca se interpreta como seguridad.

## 6. Testing exigido

- `TenantIsolationTest` para `IncidentDriverContact` y para los teléfonos.
- Resolución por canal: user con/sin phone; contacto externo E.164 vs email; canal omitido cuando falta el dato.
- Protocolo: no hay contacto automático en pánico activo; el operador dispara; duress → escala + bloquea reintentos; silencio → escala; safe → baja sospecha.
- Fallback opt-in on/off (respeta default apagado).
- OTP: envía, verifica, expira, rate-limit; idempotencia del reenvío.
- Escalación: avanza por niveles, resuelve rol/user_ids/contacts, el ack detiene, canales por nivel.
- Factories siempre; `Storage`/`Event` fakes donde aplique; sin `Model::create()` manual.

## 7. Fuera de alcance (diferido)

- App de conductor / push silencioso (no existe; D1).
- Distinción de tipo de pánico desde Samsara (hoy es genérico).
- Integración real con autoridades/911: se modela como acción/plantilla configurable, no como integración externa.
- Billing externo (Stripe): sigue metered local.

## 8. Riesgos y mitigaciones

- **Mensaje discreto mal configurado por el tenant** podría delatar al conductor → default conservador + texto guía + validación de que el mensaje no contenga términos de alerta.
- **Costo Twilio en escalaciones** → niveles con delays, ack que detiene, todo metered y visible por tenant.
- **Número verificado pero luego inválido** → reintentos registran fallo; el operador ve el estado en el timeline.
