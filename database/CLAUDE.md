# Base de datos (database/)

- Tabla tenant-scoped: `foreignId('team_id')->constrained()->cascadeOnDelete()` + `index('team_id')`. `nullable()` **sólo** si la fila puede ser catálogo global de plataforma (`team_id` null), documentado con un comentario en la migración.
- Columnas JSON con sufijo `_json`.
- Al crear un modelo, crear también su factory (y seeder si aplica).
- **Factories:** un modelo hijo se crea en el MISMO tenant que su padre. No le pongas un `Team::factory()` propio a cada relación: fabrica datos imposibles en producción y enmascara fugas (pasó con `NormalizedEventFactory`).
- Seeders de tenant corren dentro de `TenantContext::for($teamId, ...)`.
