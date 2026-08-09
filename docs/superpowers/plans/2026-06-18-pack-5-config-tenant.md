# Pack 5 — Config del tenant sin fricción (P1)

> **Objetivo:** quitar la sensación de "complejo de configurar".
> **Origen:** spec §3 Pack 5 · hallazgos `AUDITORIA.md` A11 (enum inglés, on-call JSON crudo), Frontend (monolito, claves técnicas crudas).

## Diseño

### 5.1 Settings críticos editables (4.1)
- Convertir la tabla read-only "Otros settings" en **formularios guiados** para las claves que ya tienen `SETTING_LABELS`: teléfonos de verificación por voz, umbral offline, ventana de correlación, ventanas de media, umbral de confianza de reeval (Pack 3).
- El backend ya acepta el batch tipado (`TenantConfigController`); falta la UI de inputs con validación en vivo.
- **Quitar claves técnicas crudas** de las etiquetas (p.ej. "(panic.auto_close_on_external_resolution)") → label humano + texto de ayuda contextual.
- **Traducir enum values** del AI profile a español en los `<select>` (`medium`/`high`/`assisted`/`optional` → "Media"/"Alta"/"Asistido"/"Opcional").

### 5.2 On-call (4.2)
- Editor **visual** de turnos (días/horas/responsables) en vez del `<textarea>` JSON crudo.
- Permitir **crear** un perfil de horario desde la UI (`POST settings/tenant-config/schedule` → `TenantScheduleProfileController`), no solo editar.
- Validación de estructura inline; label 100% español.

### 5.3 Mantenibilidad
- Trocear el monolito `tenant-config.tsx` (2332 L) en componentes por tab (`TenantConfigAiTab`, `TenantConfigScheduleTab`, `TenantConfigSettingsTab`, `TenantConfigMediaTab`, …) con carga diferida.
- Selector de zona horaria IANA MX (`America/Mexico_City`, `America/Monterrey`, `America/Mazatlan`, `America/Chihuahua`, `America/Tijuana`, `America/Cancun`) en vez de input de texto libre.

## Tests
- Inertia: cada tab renderiza su componente; `tenant-config` postea el batch tipado y persiste (feature test del controller).
- `TenantScheduleProfileStoreTest`: crear perfil de horario desde la UI (happy path + validación + aislamiento de tenant).
- Validación: setting fuera de rango → error en español útil.

## Criterios de aceptación (del spec)
- [ ] Un admin de tenant configura teléfonos de verificación, umbral de heartbeat y turnos on-call **sin tocar JSON ni ver claves técnicas**.

## Archivos clave
`resources/js/pages/settings/tenant-config.tsx` (trocear), `app/Http/Controllers/.../TenantConfigController.php`, `app/Http/Controllers/.../TenantScheduleProfileController.php`, `routes/web.php`, mapa `SETTING_LABELS` + traducciones de enums.

## Riesgo
Bajo-medio. El troceo del monolito es mecánico pero amplio; hacerlo por tab con tests Inertia por componente para no romper rutas.
