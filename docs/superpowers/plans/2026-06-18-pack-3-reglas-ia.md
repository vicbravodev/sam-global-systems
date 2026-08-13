# Pack 3 — Reglas del flujo IA alineadas al spec (P1)

> **Objetivo:** que el comportamiento de la IA coincida con lo pedido por el dueño.
> **Origen:** spec §3 Pack 3 · hallazgos `AUDITORIA.md` C6 (solo-imágenes), A1 (reeval), A9 (correlación), AI-A (resumen de visión al agente).

## Decisión de la pregunta abierta §5.4
**Umbral de re-evaluación por tenant** (`TenantAIProfile.reevaluation_confidence_threshold`), con **fallback** a `config('ai.reevaluation_confidence_threshold', 0.5)`.

## Diseño

### 3.1 Solo imágenes (estricto) — cierra C6
- En `AssessPendingMediaOnEvaluationCompleted` (sweep) y `EvaluateEventMediaJob` (por-llegada): filtrar la colección a `MediaType::Image` / `MediaType::Snapshot`; excluir `Clip`/`Video`/`Audio` **antes** de llamar al agente.
- `EvaluateEventMultimodally::resolveAssessmentType`: clips/video/audio → marcar `result = Unavailable` con motivo `solo_imagenes`, **sin** consumir tokens (no enviarlos como `Document`).
- `config/ai.php`: `assessable_media_types => ['image','snapshot']` para que sea explícito y testeable.

### 3.2 Re-evaluación por ambigüedad — cierra A1
- Nuevo listener `RequestReevaluationOnAmbiguousEvaluation` suscrito a `AIEvaluationCompleted`: si `classification ∈ {Unclear, PendingEvidence}` **o** `confidence_score < umbral(tenant→config)`, encola `ReevaluateEventJob` con trigger `ManualReviewRequested`/`ContextUpdated`.
- **Anti-loop:** tope `config('ai.max_reevaluation_version')` (p.ej. 3); el job aborta si `evaluation.version >= tope`. Reusar el debounce/`ShouldBeUniqueUntilProcessing` existente.
- En re-evaluación, **incluir el resumen de assessments de visión** (`AIMediaAssessment.summary_text`/`extracted_signals_json`) en el input del clasificador (`BuildAIInputContext`) — hoy ese dato solo llega a las reglas de decisión, no al prompt.
- Si tras el tope sigue `unclear`, transición a "requiere humano" (enlaza con Pack 1: incidente tomable).

### 3.3 Correlación direccional + passenger — cierra A9
- `LoadRecentAssetHistory::correlateNearbySafetyEvents`: añadir `offset_seconds` (firmado: negativo = antes, positivo = después del panic) a cada entrada de `nearby_safety_breakdown`.
- `EventClassifierAgent`: instruir explícitamente que un `passenger_detected`/maniobra **antes** del panic es señal de coerción.
- Passenger detection: añadir `compliance` (acotado a `unauthorized_passenger`, `tampering`, `camera_obstructed`) al filtro de correlación, **o** remapear `unauthorized_passenger` a `emergency` en `NormalizationSeeder`. Recomendado: ampliar el filtro (menos disruptivo que recategorizar).
- `SignalsBuilder`: exponer señal `passenger_before_panic` derivada del breakdown direccional.

## Tests
- `AssessmentImageOnlyTest`: evento con clip+imagen → solo la imagen se evalúa; el clip queda `Unavailable/solo_imagenes` sin llamada al agente (`Http::fake` assert count).
- `ReevaluateOnLowConfidenceTest`: evaluación `unclear`/`0.35` → se encola `ReevaluateEventJob`; con `version>=tope` no se re-encola (anti-loop).
- `DirectionalCorrelationTest`: safety event 90s **antes** del panic → `offset_seconds = -90` en el breakdown.
- `PassengerBeforePanicSignalTest`: `unauthorized_passenger` previo entra a la correlación y `passenger_before_panic=true`.
- `VisionSummaryReachesTextAgentTest`: en reeval, el input del clasificador contiene el `summary_text` del assessment.

## Criterios de aceptación (del spec)
- [ ] Ningún assessment de IA se ejecuta sobre video/audio.
- [ ] Un panic `unclear`/baja confianza dispara re-evaluación (con tope de versiones).
- [ ] El breakdown distingue "antes" vs "después"; una detección de pasajeros previa queda reflejada en señales que la IA consume.

## Archivos clave
`app/Domains/AI/Actions/EvaluateEventMultimodally.php`, `app/Domains/AI/Listeners/AssessPendingMediaOnEvaluationCompleted.php`, `app/Domains/AI/Jobs/EvaluateEventMediaJob.php`, nuevo `Listeners/RequestReevaluationOnAmbiguousEvaluation.php`, `app/Domains/AI/Actions/EvaluateEventWithAI.php` + `BuildAIInputContext.php`, `app/Domains/Context/Actions/LoadRecentAssetHistory.php`, `app/Domains/Context/Support/SignalsBuilder.php`, `app/Domains/AI/Support/EventClassifierAgent.php`, `database/seeders/NormalizationSeeder.php`, `config/ai.php`, `TenantAIProfile`.

## Riesgo
Bajo-medio. El filtro de imágenes y el offset son aislados; la reeval por ambigüedad necesita el tope anti-loop bien probado para no entrar en bucle de evaluación.
