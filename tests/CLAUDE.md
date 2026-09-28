# Tests (tests/)

- PHPUnit 13, clases en `tests/Feature/...` (`php artisan make:test --phpunit --no-interaction Nombre`). Pest no está instalado: si ves uno, conviértelo.
- Todo cambio lleva test. Corre sólo los afectados con `--filter` y la suite completa antes de cerrar un módulo o hacer push.
- Factories siempre (revisa sus states); nunca `Model::create()` manual. `RefreshDatabase` + DB real; no mockear la DB. Los tests corren en SQLite en memoria.
- Un test por Action y por Job crítico; happy path, fallos y bordes.
- **Idempotencia:** todo lo que recibe `event_key`, firma o webhook tiene un test de duplicado sin efectos secundarios.
- **Fuga de tenant (bloqueante):** además del `TenantIsolationTest` del dominio, cada feature aporta un test sobre su camino real con `Tests\Concerns\AssertsTenantIsolation`: `$this->assertNoTenantLeak($teamB, fn () => ...)` falla si se tocaron datos de otro tenant o se devolvieron modelos ajenos. Plantilla: `Feature/Domains/Normalization/NormalizeEventJobTenantLeakTest.php`. Un `actingAs($a); assertSame(2, Model::count())` sólo prueba el scope de Laravel: no cuenta. Si el riesgo es ejecutar un recurso ajeno escribiendo en el propio tenant, asértalo directamente (el helper no lo ve).
- Página Inertia nueva o modificada: `assertInertia(fn (AssertableInertia $page) => $page->component('...')->has(...))`. Endpoint nuevo: happy path + policy + aislamiento.
- Fakes: `Storage::fake('rustfs')`, `Event::fake([...Broadcast::class])`.
- No borres tests ni archivos de `tests/` sin aprobación.
