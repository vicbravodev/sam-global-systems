# 02 · Arquitectura de Información — SAM

Mapa de módulos → pantallas → objetos → estados. Esta es la superficie que el design system tiene que cubrir. Todo vive bajo `/{team_slug}/...` (multi-tenant).

## Navegación global (sidebar)

Sidebar colapsable + team switcher arriba + user menu abajo. Agrupación sugerida:

```
OPERACIÓN
  · Dashboard                    (home operativo)
  · Incidentes                   (bandeja, filtros, SLA)
  · Eventos                      (stream normalizado, debug/audit)
  · Mapa en vivo                 (activos geolocalizados en tiempo real)

RECURSOS
  · Activos                      (vehículos, cámaras, dispositivos)
  · Conductores                  (personas, riesgo, documentos)

INTELIGENCIA
  · Reglas                       (motor de decisiones)
  · Automatizaciones             (acciones ejecutables)
  · Analítica                    (tendencias, SLA, KPIs)

CONFIGURACIÓN
  · Integraciones                (proveedores, webhooks)
  · Notificaciones               (canales, plantillas)
  · Auditoría                    (logs del tenant)
  · Configuración                (tenant, branding, plan, features)
  · Equipo y roles               (usuarios, permisos, invitaciones)
```

La navegación debe soportar **badge con contador en vivo** (ej: `Incidentes · 14`) alimentado por broadcasting (canal `private-accounts.{teamId}`).

---

## Módulos y pantallas

Orden = orden de implementación real en el repo. Los módulos 01–07 ya existen en backend; 08–16 llegan después, pero el DS tiene que estar listo para todos.

### 01 · Tenancy & Billing

**Objetivo UX:** que el admin vea en segundos consumo, límites y costo.

Pantallas:
- **Plan & Uso** — tabla de `UsageMeter` (eventos, incidentes AI, storage) con barras de consumo vs límite, proyección del ciclo, botón "Cambiar plan".
- **Facturación** — snapshots de facturas (`InvoiceSnapshot`), estados de Stripe, descarga PDF.
- **Branding del tenant** — logo, color primario, dominio custom (vive en `TenantBranding`).
- **Features flags** — qué features están activos por plan/tenant.

Componentes clave: `UsageMeterCard`, `PlanCompareTable`, `InvoiceList`, `BrandingPreview` (muestra el app shell con el branding aplicado en vivo).

### 02 · Access (Usuarios, Roles, Permisos)

- **Miembros del equipo** — lista, rol, último acceso, invitar, remover.
- **Roles & permisos** — matriz permiso × rol (editable).
- **Invitaciones pendientes** — tabla con estado, reenviar, cancelar.
- **Perfil + preferencias** — foto, 2FA, zona horaria, preferencias UI.

Componentes clave: `PermissionMatrix`, `InviteMemberModal` (ya existe), `RoleBadge`.

### 03 · Integraciones

- **Lista de integraciones** — providers conectados, estado (`healthy / degraded / broken`), última sync.
- **Detalle de integración** — credenciales, webhook URL pública, secret, rotación, historial de recepción.
- **Webhook logs** — stream de raw events recibidos, con firma válida/inválida, payload inspeccionable.
- **Wizard de conexión** — pasos guiados para añadir un provider nuevo.

Componentes clave: `IntegrationCard` (con status dot), `WebhookLogRow` (colapsable, pretty-JSON), `ProviderLogo`, `SecretField` (reveal/copy/rotate).

### 04 · Activos

- **Lista/Mapa de activos** — toggle tabla/mapa, filtros por tipo, estado, grupo.
- **Detalle de activo** — datos, dispositivos asociados, última ubicación, histórico de telemetría, incidentes recientes.
- **Tipos de activo** — taxonomía editable (`AssetType`).
- **Grupos** — clustering de activos (flotas, rutas, geocercas).

Componentes clave: `AssetCard`, `AssetStatusPill` (idle / active / offline / maintenance), `LocationMap` (leaflet/maplibre), `TelemetryChart` (sparkline + full).

### 05 · Conductores

- **Lista de conductores** — foto, nombre, score de riesgo, asignación actual, estado.
- **Detalle de conductor** — perfil, documentos (licencia vigente, contratos), histórico de asignaciones, riesgo, incidentes involucrados.
- **Documentos vencidos** — alertas de documentos por caducar.

Componentes clave: `DriverCard`, `RiskMeter` (0–100), `DocumentStatusBadge`, `AssignmentTimeline`.

### 06 · Ingestión (casi siempre oculto, técnico)

- **Raw events stream** — para debugging, no uso operativo diario. Vista tipo log.
- **Fuentes** (`EventSource`) y duplicados detectados.
- **Adjuntos no procesados** — cola de media pendiente.

Componentes clave: `EventRow`, `JsonInspector`, `DedupBadge`.

### 07 · Normalización

- **Catálogo** — categorías, severidades, tipos de evento (`EventCategory/Severity/Type`).
- **Reglas de mapeo** — payload externo → evento normalizado.
- **Playground** — pegar un payload y ver cómo se normalizaría.

Componentes clave: `MappingRuleEditor`, `JsonDiff`, `SeverityPill`.

### 08 · Contexto Operativo

- **Overlays de contexto** — vista que muestra, para un evento, el contexto que la IA usó (clima, tráfico, histórico del conductor, geocerca).
- No suele tener página propia; aparece como panel dentro del detalle de incidente/evento.

### 09 · AI / Evaluación Inteligente

- **Evaluaciones recientes** — log de decisiones de la IA con score de confianza, modelo usado, latencia, costo.
- **Detalle de evaluación** — input (evento + contexto), output (clasificación + razonamiento), tokens usados.
- **Feedback humano** — marcar eval como correcta/incorrecta (retroalimentación).

Componentes clave: `AiEvaluationCard`, `ConfidenceBar`, `ModelTag`, `ReasoningDisclosure` (colapsable con el chain-of-thought resumido).

### 10 · Reglas & Motor de Decisiones

- **Editor visual de reglas** — condiciones (evento + contexto) → decisión (`DISCARD / INFO / INCIDENT / ESCALATE`).
- **Simulador** — correr la regla contra eventos recientes para ver impacto.
- **Historial de cambios** — quién cambió qué regla, cuándo.

Componentes clave: `RuleBuilder` (no-code), `DecisionOutcomeBadge`, `RuleSimulator`.

### 11 · Incidentes (⭐ pantalla más crítica)

**Bandeja de incidentes** (inbox-style):
- Lista densa, multi-selección, filtros salvables, vista dividida (list + detail).
- Columnas: severidad, tipo, activo, conductor, asignado a, SLA, edad, estado.
- Ordenar por SLA ascendente por default.
- Realtime: nuevos incidentes aparecen con animación breve (<150ms), con posibilidad de "pin new items" sin saltar el scroll.

**Detalle de incidente**:
- Header: título, tipo, prioridad, estado actual, SLA countdown.
- Columna izquierda: timeline narrativo (`IncidentTimeline`) — source de verdad del caso.
- Columna central: descripción, evento origen, explicación de IA, evidencia (media gallery).
- Columna derecha: asignación actual, relacionados (activo, conductor, otros eventos), acciones (asignar, reclasificar, agregar evidencia, cerrar).
- Footer: caja de comentarios (`IncidentComment`) con visibility toggle (internal/tenant/audit).

**Cierre de incidente** — modal con `resolution_code`, resumen, root cause, acción correctiva/preventiva.

Componentes clave (todos de dominio, no triviales):
- `IncidentRow` (lista densa)
- `IncidentDetailLayout` (3 columnas colapsables)
- `IncidentTimeline` (entry_type → icono + color + actor)
- `SeverityBadge`, `StatusPill`, `PriorityIndicator`, `SlaCountdown` (cuenta regresiva viva, cambia color al acercarse)
- `EvidenceGallery` (imagen/video/doc/snapshot telemetría)
- `AssignmentPanel` (usuario/equipo/cola)
- `CloseIncidentModal`
- `CommentThread` con `VisibilityToggle`
- `RelatedEventsPanel` (`IncidentEventLink`)

### 12 · Automatización

- **Lista de automatizaciones** — trigger, condición, acción, estado.
- **Editor de automatización** — similar al rule builder pero con acciones ejecutables.
- **Run history** — ejecuciones, éxito/fallo, evidencia de la ejecución.

Componentes clave: `AutomationCard`, `ActionPicker`, `RunHistoryTable`.

### 13 · Notificaciones

- **Canales** — email, webhook, slack, teams, SMS. Estado de cada canal.
- **Plantillas** — editor con preview (markdown + variables del evento).
- **Log de envíos** — qué se envió, a quién, cuándo, entregado/fallido.

Componentes clave: `ChannelCard`, `TemplateEditor`, `VariableChip`, `DeliveryStatusBadge`.

### 14 · Auditoría

- **Audit log** — filtrable por usuario, módulo, acción, rango de fechas.
- **Detalle** — diff de cambios, IP, user-agent.
- Export a CSV/JSON para compliance.

Componentes clave: `AuditRow`, `DiffViewer` (antes/después lado a lado).

### 15 · Analítica / Reportes

- **Dashboard ejecutivo** — KPIs: MTTR, % false positive AI, SLA cumplido, incidentes por severidad, tendencia 30d.
- **Reportes** — plantillas predefinidas + custom.
- **Exportables** — PDF, CSV, programable por cron.
- Tema gráfico: series temporales, stacked bars, heatmaps por hora/día de la semana.

Componentes clave: `KpiTile` (valor grande + delta + sparkline), `TrendChart`, `Heatmap`, `SlaWidget`, `ReportBuilder`.

### 16 · Configuración por Tenant

- **General** — nombre, timezone, idioma default, formatos.
- **Branding** — logo, color, favicon, dominio custom.
- **Features** — toggles por feature flag.
- **Límites** — umbrales de alerta, caps de uso.
- **Integración de datos** — retención, PII masking.

Componentes clave: `SettingSection`, `FeatureToggle`, `ColorPicker` (con preview en vivo del shell).

---

## Objetos operativos críticos (mapa mental para diseñar)

Este es el "modelo operativo" que el usuario tiene en la cabeza. El DS debe tener un tratamiento visual único y consistente para cada uno:

| Objeto | Cómo lo piensa el usuario | Tratamiento visual |
|--------|---------------------------|--------------------|
| **Evento** | "algo pasó" (raw o normalizado) | Pill neutra, timestamp prominente |
| **Incidente** | "un caso abierto" | Card destacada, con severidad + SLA |
| **Decisión AI** | "la IA dice que..." | Badge con confianza + razonamiento desplegable |
| **Activo** | "mi vehículo/cámara" | Card con estado + foto/icono por tipo |
| **Conductor** | "mi persona" | Card con foto + riesgo |
| **Acción/Automatización** | "esto se hizo solo" | Tag con icono de robot/zap |
| **Regla** | "así decidimos" | Block tipo IF/THEN |
| **Integración** | "por aquí entran datos" | Card con logo del provider + health |

---

## Estados globales transversales

Cada pantalla con datos debe soportar los siguientes estados. El DS tiene que definir un **patrón único** para cada uno:

1. **Loading inicial** — skeletons que respeten el layout final (no spinners centrados).
2. **Loading incremental (realtime)** — nuevo item entra con highlight de 1–2s, luego estado normal.
3. **Empty state** — nunca una ilustración grande. Texto corto + CTA específica (ej: "No hay incidentes abiertos. ¿Conectar una integración?").
4. **Error recuperable** — banner inline con "Reintentar", no modal.
5. **Error fatal** — página de error con un solo CTA.
6. **Permiso denegado** — estado explícito, no un 404 ambiguo.
7. **Desconexión de realtime** — indicador no intrusivo en el shell ("Reconectando…") + fallback a polling.
8. **Degradación de proveedor externo** — banner en la pantalla afectada ("Integración Samsara degradada, eventos con retraso").

## Patrones de interacción que deben existir

- **Command palette (⌘K)** — saltar a cualquier incidente, activo, conductor, página.
- **Shortcuts de teclado** — `j/k` para navegar listas, `e` para asignar, `c` para cerrar, `r` para reclasificar, `?` para ver todos los shortcuts.
- **Bulk actions** — multi-selección en listas (asignar masivo, cerrar masivo).
- **Filtros guardables** — "Mis incidentes abiertos de hoy" como filtro nombrado.
- **Split-pane opcional** — lista + detalle en la misma pantalla para bandejas (email-like).
- **Timestamps duales** — relativo visible + absoluto en tooltip, con zona horaria del tenant.
- **Copy-on-click** en IDs, hashes, URLs de webhook, event_key.
