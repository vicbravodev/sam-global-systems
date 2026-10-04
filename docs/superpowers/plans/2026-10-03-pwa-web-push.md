# SAM PWA + Web Push Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que las alertas críticas, la escalera de SLA y las asignaciones lleguen como notificación del sistema al teléfono/escritorio aunque SAM esté cerrado, vía Web Push estándar (VAPID), instalando SAM como PWA.

**Architecture:** El canal `ChannelType::Push` ya está cableado en la política crítica y en la escalera por defecto; se reemplaza su driver FCM (muerto) por uno VAPID (`minishlink/web-push`) que lee suscripciones de una tabla nueva `push_subscriptions` (usuario + team). El navegador se suscribe con `PushManager` nativo desde "Mis avisos" y un `public/sw.js` escrito a mano muestra la notificación y abre el incidente. Un listener nuevo avisa al asignado de un incidente.

**Tech Stack:** Laravel 13 · PHP 8.5 · `minishlink/web-push` · PostgreSQL · Inertia v3 + React 19 · Service Worker + Push API · PHPUnit 13.

**Spec:** `docs/superpowers/specs/2026-10-03-pwa-web-push-design.md`

## Global Constraints

- Tenant = `Team`. Ninguna query de `push_subscriptions` sin `team_id`; todo test de feature nuevo lleva su caso de fuga (`AssertsTenantIsolation`).
- Logging narrativo con `App\Support\SystemLog` (`ok/skipped/degraded/failed`), códigos `dominio.etapa.resultado`, documentados en `docs/SAM/logging.md`. **Nunca** se loguea endpoint, llaves (`p256dh`/`auth`), título ni cuerpo. Cada test de logging cierra con `assertNoSensitiveDataLogged()`.
- Copy en es-MX, de tú, sin jerga en inglés (nada de "push", "SLA", "on-call" en textos visibles).
- Commits: `type: subject en minúsculas`, **sin** `Co-Authored-By` ni banners (regla del repo, manda sobre cualquier otra).
- No `@phpstan-ignore`, no tocar `phpstan-baseline.neon`. No mockear la DB.
- Frontend: Wayfinder para URLs, `lib/sam-fetch` para JSON, sin `useMemo/useCallback`, tokens de `@theme` (sin tamaños arbitrarios), botones con `type`.
- Dependencias: se agrega `minishlink/web-push` y se quita `kreait/firebase-php` (autorizado por el usuario el 2026-10-03). Nada de vite-plugin-pwa/workbox.
- Push es complemento: nunca cuenta como "contacto que interrumpe" ni entra en el cooldown pagado.
- Tras `composer require/remove`: `docker compose restart horizon scheduler` en dev (autoloader viejo en workers).

## Review Focus

1. **Navegador compartido / cambio de usuario**: si B inicia sesión en el navegador donde A activó avisos, A no debe seguir recibiendo ahí. → el front re-sincroniza la suscripción al cargar (upsert por `endpoint_hash` reasigna usuario y team); test en Task 6 (`test_same_endpoint_moves_to_the_new_user_and_team`).
2. **Usuario quitado del team**: su dispositivo no debe recibir avisos del team que lo sacó. → el driver verifica membresía; test en Task 4 (`test_fails_when_user_is_no_longer_a_member`).
3. **Suscripción del mismo usuario en otro team** no recibe avisos de este team. → test en Task 4 (`test_does_not_send_to_the_users_subscription_in_another_team`).
4. **Push entregado no frena el reintento de un SMS/llamada crítica fallida.** → test en Task 5 (`test_a_delivered_push_does_not_count_as_reaching_the_recipient`).
5. **Servidor sin llaves VAPID** (dev, staging recién creado): nada explota, la entrega falla con razón clara y la UI no ofrece activar. → tests en Task 4 (`test_fails_degraded_when_vapid_is_not_configured`) y Task 6 (`test_shares_null_public_key_when_not_configured`).

---

## File Structure

**Backend — crear**
- `config/webpush.php` — llaves VAPID desde env.
- `app/Console/Commands/GenerateVapidKeys.php` — `sam:vapid-keys`.
- `database/migrations/2026_10_08_100000_replace_user_push_tokens_with_push_subscriptions.php`
- `database/migrations/2026_10_08_100100_seed_platform_push_channel.php`
- `app/Domains/Notifications/Models/PushSubscription.php`
- `database/factories/Domains/Notifications/PushSubscriptionFactory.php`
- `app/Domains/Notifications/Data/WebPushTarget.php`, `WebPushOutcome.php`
- `app/Domains/Notifications/Channels/WebPushMessenger.php` — envoltura de minishlink (como `TwilioMessenger`; se sustituye en tests con `app()->instance`). *Desviación del spec:* el spec decía contrato en `app/Contracts` + implementación en `app/Infrastructure/`; se sigue el patrón existente de `Channels/*Messenger` para no crear directorios nuevos en `app/`.
- `app/Domains/Notifications/Support/PushPayload.php` — arma el JSON (título, cuerpo, url, tag, critical).
- `app/Http/Controllers/Settings/PushSubscriptionController.php`
- `app/Http/Requests/Settings/StorePushSubscriptionRequest.php`
- `app/Domains/Notifications/Listeners/NotifyOnIncidentAssigned.php`

**Backend — modificar**
- `composer.json` / `composer.lock`, `.env.example`
- `app/Domains/Notifications/Channels/PushNotificationDriver.php` (reescritura)
- `app/Domains/Notifications/Models/NotificationRecipient.php:40-47`
- `app/Domains/Notifications/Enums/ChannelType.php:40` (etiqueta)
- `app/Domains/Notifications/Support/EncryptedChannelConfigCast.php:33`
- `app/Domains/Notifications/Support/ProvidedChannels.php` (docblock)
- `app/Domains/Notifications/NotificationsServiceProvider.php`
- `app/Domains/Notifications/Support/NotificationTypeLabels.php`
- `app/Domains/Incidents/Support/IncidentNoticeCopy.php`
- `app/Domains/Incidents/Listeners/AssignOnCallOnIncidentCreated.php:100-163`
- `app/Http/Controllers/Settings/NotificationPreferencesController.php:25-29`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `app/Models/User.php:80-86`
- `database/seeders/PlatformChannelSeeder.php`
- `database/factories/Domains/Notifications/NotificationChannelFactory.php:65-70`
- `routes/settings.php`
- `docs/SAM/logging.md`

**Backend — borrar**
- `app/Domains/Notifications/Channels/FcmMessenger.php`, `app/Domains/Notifications/Data/FcmSendReport.php`, `app/Domains/Notifications/Models/UserPushToken.php`, `database/factories/Domains/Notifications/UserPushTokenFactory.php`, `tests/Feature/Domains/Notifications/UserPushTokenTest.php`

**Frontend — crear**
- `public/manifest.webmanifest`, `public/sw.js`, `public/icons/icon-192.png`, `icon-512.png`, `icon-maskable-512.png`
- `resources/js/lib/web-push.ts`
- `resources/js/hooks/use-web-push.ts`
- `resources/js/components/sam/settings/device-push-card.tsx`
- `resources/js/components/push-opt-in-banner.tsx`

**Frontend — modificar**
- `resources/views/app.blade.php`, `resources/js/types/global.d.ts`, `resources/js/pages/settings/notifications.tsx`, `resources/js/layouts/ops-layout.tsx`

**Tests — crear/modificar**
- `tests/Feature/Domains/Notifications/GenerateVapidKeysCommandTest.php`
- `tests/Feature/Domains/Notifications/PushSubscriptionModelTest.php`
- `tests/Feature/Domains/Notifications/Drivers/PushNotificationDriverTest.php` (reescritura)
- `tests/Feature/Domains/Notifications/PushChannelRoutingTest.php`
- `tests/Feature/Settings/PushSubscriptionControllerTest.php`
- `tests/Feature/Domains/Incidents/NotifyOnIncidentAssignedTest.php`
- `tests/Feature/Domains/Notifications/RecipientChannelAddressTest.php`, `PlatformChannelSeederTest.php`, `EncryptedChannelConfigCastTest.php`, `Drivers/ChannelDriverRegistryTest.php` (ajustes)

---

### Task 1: Dependencia, configuración VAPID y comando de llaves

**Files:**
- Modify: `composer.json`, `composer.lock`, `.env.example`
- Create: `config/webpush.php`, `app/Console/Commands/GenerateVapidKeys.php`
- Test: `tests/Feature/Domains/Notifications/GenerateVapidKeysCommandTest.php`

**Interfaces:**
- Produces: `config('webpush.vapid.subject'|'webpush.vapid.public_key'|'webpush.vapid.private_key')` (string|null); comando `sam:vapid-keys`.

- [ ] **Step 1: Instalar la dependencia**

Run: `composer require minishlink/web-push`
Expected: se agrega `"minishlink/web-push": "^9.x"` (o la mayor vigente) a `require`. Si composer se queja de PHP 8.5, revisar la última versión con `composer show -a minishlink/web-push` y fijar la que declare compatibilidad; no usar `--ignore-platform-reqs`.

- [ ] **Step 2: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Domains\Notifications;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class GenerateVapidKeysCommandTest extends TestCase
{
    public function test_prints_a_fresh_vapid_key_pair_for_the_env_file(): void
    {
        $exitCode = Artisan::call('sam:vapid-keys');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertMatchesRegularExpression('/^VAPID_PUBLIC_KEY=[A-Za-z0-9_-]{80,}$/m', $output);
        $this->assertMatchesRegularExpression('/^VAPID_PRIVATE_KEY=[A-Za-z0-9_-]{40,}$/m', $output);
    }

    public function test_config_reads_vapid_keys_from_env(): void
    {
        $this->assertArrayHasKey('subject', config('webpush.vapid'));
        $this->assertArrayHasKey('public_key', config('webpush.vapid'));
        $this->assertArrayHasKey('private_key', config('webpush.vapid'));
    }
}
```

- [ ] **Step 3: Correrlo y verificar que falla**

Run: `php artisan test --compact --filter=GenerateVapidKeysCommandTest`
Expected: FAIL (`The command "sam:vapid-keys" does not exist`).

- [ ] **Step 4: Implementar config y comando**

`config/webpush.php`:

```php
<?php

/*
 * Avisos al dispositivo (Web Push, VAPID). Las llaves son de la plataforma,
 * no de cada tenant: se generan una vez con `php artisan sam:vapid-keys`.
 * Sin llaves el canal no se ofrece y el driver falla con `not_configured`.
 */
return [
    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],
];
```

`app/Console/Commands/GenerateVapidKeys.php`:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    protected $signature = 'sam:vapid-keys';

    protected $description = 'Genera un par de llaves VAPID para los avisos al dispositivo (pegar en .env)';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->comment('Pega ambas en .env junto con VAPID_SUBJECT (p. ej. mailto:soporte@tu-dominio). Cambiarlas invalida todas las suscripciones.');

        return self::SUCCESS;
    }
}
```

`.env.example` (al final, en su propio bloque):

```
VAPID_SUBJECT=
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
```

- [ ] **Step 5: Correr el test**

Run: `php artisan test --compact --filter=GenerateVapidKeysCommandTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock .env.example config/webpush.php app/Console/Commands/GenerateVapidKeys.php tests/Feature/Domains/Notifications/GenerateVapidKeysCommandTest.php
git commit -m "feat: llaves vapid y comando sam:vapid-keys para avisos al dispositivo"
```

---

### Task 2: Retirar FCM y crear `push_subscriptions`

**Files:**
- Create: `database/migrations/2026_10_08_100000_replace_user_push_tokens_with_push_subscriptions.php`, `app/Domains/Notifications/Models/PushSubscription.php`, `database/factories/Domains/Notifications/PushSubscriptionFactory.php`
- Modify: `app/Models/User.php:80-86`, `app/Domains/Notifications/Support/EncryptedChannelConfigCast.php:33`, `tests/Feature/Domains/Notifications/EncryptedChannelConfigCastTest.php:124`, `database/factories/Domains/Notifications/NotificationChannelFactory.php:65-70`, `composer.json`
- Delete: `FcmMessenger.php`, `FcmSendReport.php`, `UserPushToken.php`, `UserPushTokenFactory.php`, `UserPushTokenTest.php`
- Test: `tests/Feature/Domains/Notifications/PushSubscriptionModelTest.php`

**Interfaces:**
- Produces: `App\Domains\Notifications\Models\PushSubscription` con `team_id`, `user_id`, `endpoint`, `endpoint_hash`, `public_key`, `auth_token` (cast `encrypted`), `content_encoding`, `device_label`, `last_used_at` (datetime); `PushSubscription::hashEndpoint(string $endpoint): string`; `User::pushSubscriptions(): HasMany`; factory `PushSubscriptionFactory` con estado `forMember(User $user, Team $team)`.

> Nota: `PushNotificationDriver` sigue referenciando FCM hasta Task 4. En este task se deja el driver **compilando** reemplazando su cuerpo por un `failure('push_not_implemented')` temporal y se borra su test viejo; Task 4 lo reescribe con TDD.

- [ ] **Step 1: Escribir el test del modelo que falla**

```php
<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PushSubscriptionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscriptions_are_tenant_scoped(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        PushSubscription::factory()->forMember($userA, $userA->currentTeam)->create();
        PushSubscription::factory()->forMember($userB, $userB->currentTeam)->create();

        $this->actingAs($userA->fresh());

        $this->assertSame(1, PushSubscription::query()->count());
        $this->assertSame($userA->id, PushSubscription::query()->value('user_id'));
    }

    public function test_endpoint_hash_is_unique(): void
    {
        $user = User::factory()->create();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/abc';

        PushSubscription::factory()->forMember($user, $user->currentTeam)->create(['endpoint' => $endpoint]);

        $this->expectException(UniqueConstraintViolationException::class);
        PushSubscription::factory()->forMember($user, $user->currentTeam)->create(['endpoint' => $endpoint]);
    }

    public function test_auth_token_is_encrypted_at_rest(): void
    {
        $user = User::factory()->create();
        $subscription = PushSubscription::factory()->forMember($user, $user->currentTeam)->create(['auth_token' => 'secret-auth']);

        $raw = DB::table('push_subscriptions')->where('id', $subscription->id)->value('auth_token');

        $this->assertNotSame('secret-auth', $raw);
        $this->assertSame('secret-auth', $subscription->fresh()->auth_token);
    }

    public function test_user_has_many_push_subscriptions(): void
    {
        $user = User::factory()->create();
        PushSubscription::factory()->count(2)->forMember($user, $user->currentTeam)->create();

        $this->assertCount(2, $user->fresh()->pushSubscriptions);
    }

    public function test_user_push_tokens_table_is_gone(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('user_push_tokens'));
    }
}
```

- [ ] **Step 2: Correrlo y verificar que falla**

Run: `php artisan test --compact --filter=PushSubscriptionModelTest`
Expected: FAIL (`Class "App\Domains\Notifications\Models\PushSubscription" not found`).

- [ ] **Step 3: Migración**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * El canal push pasa de FCM (tokens nunca registrados) a Web Push estándar.
 * user_push_tokens nunca tuvo filas reales: se elimina sin migrar datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('user_push_tokens');

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('public_key');
            $table->text('auth_token');
            $table->string('content_encoding', 16)->default('aes128gcm');
            $table->string('device_label')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');

        Schema::create('user_push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            $table->string('token')->unique();
            $table->string('device_name')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index(['user_id', 'team_id']);
        });
    }
};
```

- [ ] **Step 4: Modelo y factory**

`app/Domains/Notifications/Models/PushSubscription.php`:

```php
<?php

namespace App\Domains\Notifications\Models;

use App\Concerns\BelongsToTenant;
use App\Models\User;
use Database\Factories\Domains\Notifications\PushSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un navegador/dispositivo que aceptó avisos de SAM (Web Push). Vale para un
 * usuario dentro de un team: el driver sólo entrega avisos de ese team. Un
 * mismo endpoint es una sola fila; volver a suscribirlo la reasigna al
 * usuario y team actuales (navegador compartido, cambio de team).
 *
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $public_key
 * @property string $auth_token
 * @property string $content_encoding
 * @property ?string $device_label
 * @property ?\Illuminate\Support\Carbon $last_used_at
 */
class PushSubscription extends Model
{
    /** @use HasFactory<PushSubscriptionFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'user_id',
        'endpoint',
        'endpoint_hash',
        'public_key',
        'auth_token',
        'content_encoding',
        'device_label',
        'last_used_at',
    ];

    protected $hidden = ['endpoint', 'public_key', 'auth_token'];

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    protected static function booted(): void
    {
        static::saving(function (self $subscription): void {
            $subscription->endpoint_hash = self::hashEndpoint($subscription->endpoint);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auth_token' => 'encrypted',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): PushSubscriptionFactory
    {
        return PushSubscriptionFactory::new();
    }
}
```

`database/factories/Domains/Notifications/PushSubscriptionFactory.php`:

```php
<?php

namespace Database\Factories\Domains\Notifications;

use App\Domains\Notifications\Models\PushSubscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PushSubscription>
 */
class PushSubscriptionFactory extends Factory
{
    protected $model = PushSubscription::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.Str::random(40),
            'public_key' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
            'auth_token' => 'tBHItJI5svbpez7KI4CCXg',
            'content_encoding' => 'aes128gcm',
            'device_label' => 'Android · Chrome',
            'last_used_at' => null,
        ];
    }

    public function forMember(User $user, Team $team): static
    {
        return $this->state(fn () => ['user_id' => $user->id, 'team_id' => $team->id]);
    }
}
```

- [ ] **Step 5: Retirar FCM**

1. `git rm app/Domains/Notifications/Channels/FcmMessenger.php app/Domains/Notifications/Data/FcmSendReport.php app/Domains/Notifications/Models/UserPushToken.php database/factories/Domains/Notifications/UserPushTokenFactory.php tests/Feature/Domains/Notifications/UserPushTokenTest.php tests/Feature/Domains/Notifications/Drivers/PushNotificationDriverTest.php`
2. `app/Models/User.php`: reemplazar `pushTokens()` por

```php
    /**
     * @return HasMany<PushSubscription, $this>
     */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }
```
   y cambiar el `use ...\UserPushToken;` por `use App\Domains\Notifications\Models\PushSubscription;`.
3. `EncryptedChannelConfigCast.php:33`: quitar `'firebase_credentials',` de la lista. En `EncryptedChannelConfigCastTest.php:124` quitar `'firebase_credentials'` de la lista esperada.
4. `NotificationChannelFactory::push()`: cambiar `'provider' => 'firebase'` por `'provider' => 'webpush'`.
5. `PushNotificationDriver.php`: dejar temporalmente

```php
<?php

namespace App\Domains\Notifications\Channels;

use App\Contracts\Notifications\NotificationDriver;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Models\NotificationChannel;

class PushNotificationDriver implements NotificationDriver
{
    public function send(RenderedNotification $notification, NotificationChannel $channel): DeliveryResult
    {
        return DeliveryResult::failure('push_not_implemented');
    }
}
```
6. `composer remove kreait/firebase-php`
7. `grep -rn -i "kreait\|firebase\|UserPushToken\|pushTokens\|FcmMessenger\|FcmSendReport" app config database tests routes` → debe quedar vacío (salvo la migración histórica `2026_05_07_120000_create_user_push_tokens_table.php`).

- [ ] **Step 6: Correr tests**

Run: `php artisan migrate:fresh --env=testing --force >/dev/null 2>&1; php artisan test --compact --filter='PushSubscriptionModelTest|EncryptedChannelConfigCastTest|ChannelDriverRegistryTest'`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add -A app database tests composer.json composer.lock
git commit -m "refactor: retira fcm y crea push_subscriptions para web push"
```

---

### Task 3: `WebPushMessenger` y sus DTOs

**Files:**
- Create: `app/Domains/Notifications/Data/WebPushTarget.php`, `app/Domains/Notifications/Data/WebPushOutcome.php`, `app/Domains/Notifications/Channels/WebPushMessenger.php`
- Test: `tests/Feature/Domains/Notifications/Drivers/WebPushMessengerTest.php`

**Interfaces:**
- Produces:
  - `WebPushTarget(int $subscriptionId, string $endpoint, string $publicKey, string $authToken, string $contentEncoding)`
  - `WebPushOutcome(int $subscriptionId, bool $success, bool $expired, ?int $statusCode, ?string $reason)`
  - `WebPushMessenger::isConfigured(): bool`
  - `WebPushMessenger::send(list<WebPushTarget> $targets, string $payload, int $ttl, string $urgency): list<WebPushOutcome>` — lanza `RuntimeException('webpush_not_configured')` si faltan llaves.

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Domains\Notifications\Drivers;

use App\Domains\Notifications\Channels\WebPushMessenger;
use App\Domains\Notifications\Data\WebPushTarget;
use Minishlink\WebPush\VAPID;
use RuntimeException;
use Tests\TestCase;

class WebPushMessengerTest extends TestCase
{
    public function test_is_not_configured_without_vapid_keys(): void
    {
        config(['webpush.vapid' => ['subject' => null, 'public_key' => null, 'private_key' => null]]);

        $this->assertFalse(app(WebPushMessenger::class)->isConfigured());
    }

    public function test_is_configured_with_all_three_values(): void
    {
        $keys = VAPID::createVapidKeys();
        config(['webpush.vapid' => ['subject' => 'mailto:soporte@example.com', 'public_key' => $keys['publicKey'], 'private_key' => $keys['privateKey']]]);

        $this->assertTrue(app(WebPushMessenger::class)->isConfigured());
    }

    public function test_send_refuses_without_configuration(): void
    {
        config(['webpush.vapid' => ['subject' => null, 'public_key' => null, 'private_key' => null]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('webpush_not_configured');

        app(WebPushMessenger::class)->send(
            [new WebPushTarget(1, 'https://example.com/push/1', 'pk', 'auth', 'aes128gcm')],
            '{}',
            60,
            'high',
        );
    }

    public function test_send_with_no_targets_returns_no_outcomes(): void
    {
        $keys = VAPID::createVapidKeys();
        config(['webpush.vapid' => ['subject' => 'mailto:soporte@example.com', 'public_key' => $keys['publicKey'], 'private_key' => $keys['privateKey']]]);

        $this->assertSame([], app(WebPushMessenger::class)->send([], '{}', 60, 'high'));
    }
}
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `php artisan test --compact --filter=WebPushMessengerTest`
Expected: FAIL (clase no existe).

- [ ] **Step 3: Implementar**

`WebPushTarget.php`:

```php
<?php

namespace App\Domains\Notifications\Data;

final readonly class WebPushTarget
{
    public function __construct(
        public int $subscriptionId,
        public string $endpoint,
        public string $publicKey,
        public string $authToken,
        public string $contentEncoding,
    ) {}
}
```

`WebPushOutcome.php`:

```php
<?php

namespace App\Domains\Notifications\Data;

/**
 * Resultado por suscripción. `expired` = el servicio de push respondió 404/410:
 * el navegador revocó la suscripción y hay que borrarla.
 */
final readonly class WebPushOutcome
{
    public function __construct(
        public int $subscriptionId,
        public bool $success,
        public bool $expired,
        public ?int $statusCode,
        public ?string $reason,
    ) {}
}
```

`WebPushMessenger.php`:

```php
<?php

namespace App\Domains\Notifications\Channels;

use App\Domains\Notifications\Data\WebPushOutcome;
use App\Domains\Notifications\Data\WebPushTarget;
use App\Support\SafeErrorMessage;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;

/**
 * Envoltura de minishlink/web-push para que PushNotificationDriver no dependa
 * del SDK (y los tests lo sustituyan con app()->instance). Las llaves VAPID
 * son de plataforma (config/webpush.php).
 */
class WebPushMessenger
{
    private const TIMEOUT_SECONDS = 10;

    public function isConfigured(): bool
    {
        $vapid = config('webpush.vapid');

        return is_array($vapid)
            && is_string($vapid['subject'] ?? null) && $vapid['subject'] !== ''
            && is_string($vapid['public_key'] ?? null) && $vapid['public_key'] !== ''
            && is_string($vapid['private_key'] ?? null) && $vapid['private_key'] !== '';
    }

    /**
     * @param  list<WebPushTarget>  $targets
     * @return list<WebPushOutcome>
     */
    public function send(array $targets, string $payload, int $ttl, string $urgency): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('webpush_not_configured');
        }

        if ($targets === []) {
            return [];
        }

        $webPush = new WebPush(
            auth: ['VAPID' => [
                'subject' => (string) config('webpush.vapid.subject'),
                'publicKey' => (string) config('webpush.vapid.public_key'),
                'privateKey' => (string) config('webpush.vapid.private_key'),
            ]],
            defaultOptions: ['TTL' => $ttl, 'urgency' => $urgency],
            timeout: self::TIMEOUT_SECONDS,
        );

        $byEndpoint = [];

        foreach ($targets as $target) {
            $byEndpoint[$target->endpoint] = $target->subscriptionId;
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $target->endpoint,
                    'publicKey' => $target->publicKey,
                    'authToken' => $target->authToken,
                    'contentEncoding' => $target->contentEncoding,
                ]),
                $payload,
            );
        }

        $outcomes = [];

        foreach ($webPush->flush() as $report) {
            $status = $report->getResponse()?->getStatusCode();

            $outcomes[] = new WebPushOutcome(
                subscriptionId: $byEndpoint[$report->getEndpoint()] ?? 0,
                success: $report->isSuccess(),
                expired: $report->isSubscriptionExpired(),
                statusCode: $status,
                reason: $report->isSuccess() ? null : SafeErrorMessage::fromString($report->getReason()),
            );
        }

        return $outcomes;
    }
}
```

> Verificar la firma real del constructor de `WebPush` y de `MessageSentReport` en `vendor/minishlink/web-push/src/` antes de escribir (los nombres de parámetros con nombre cambian entre mayores; si no coinciden, pasar posicionales `new WebPush($auth, $defaultOptions, $timeout)`). Verificar también que `App\Support\SafeErrorMessage` tenga un método para strings; si sólo tiene `from(Throwable)`, usar `Str::limit($report->getReason(), 200)` y quitar URLs con `preg_replace('#https?://\S+#', '[url]', ...)` — la razón puede incluir el endpoint, que no debe loguearse.

- [ ] **Step 4: Correr**

Run: `php artisan test --compact --filter=WebPushMessengerTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Domains/Notifications/Data/WebPushTarget.php app/Domains/Notifications/Data/WebPushOutcome.php app/Domains/Notifications/Channels/WebPushMessenger.php tests/Feature/Domains/Notifications/Drivers/WebPushMessengerTest.php
git commit -m "feat: envoltura de web push con llaves vapid de plataforma"
```

---

### Task 4: `PushNotificationDriver` con VAPID

**Files:**
- Create: `app/Domains/Notifications/Support/PushPayload.php`
- Modify: `app/Domains/Notifications/Channels/PushNotificationDriver.php`, `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/Notifications/Drivers/PushNotificationDriverTest.php`

**Interfaces:**
- Consumes: `WebPushMessenger::isConfigured()/send()`, `WebPushTarget`, `WebPushOutcome` (Task 3); `PushSubscription` (Task 2).
- Produces: `PushPayload::build(Notification $notification, Team $team, string $subject, string $body): string` (JSON con `title, body, url, tag, critical, renotify`); códigos de log `notifications.push.sent`, `notifications.push.failed`, `notifications.push.subscription_pruned`.

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Domains\Notifications\Drivers;

use App\Domains\Notifications\Channels\PushNotificationDriver;
use App\Domains\Notifications\Channels\WebPushMessenger;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Data\WebPushOutcome;
use App\Domains\Notifications\Data\WebPushTarget;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\PushSubscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class PushNotificationDriverTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private User $user;

    private Team $team;

    private NotificationChannel $channel;

    /** @var array{targets: list<WebPushTarget>, payload: string, ttl: int, urgency: string}|null */
    private ?array $sent = null;

    /** @var \Closure(list<WebPushTarget>): list<WebPushOutcome> */
    private \Closure $respond;

    private bool $configured = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
        $this->channel = NotificationChannel::factory()->push()->create(['config_json' => null]);
        $this->respond = fn (array $targets): array => array_map(
            fn (WebPushTarget $t) => new WebPushOutcome($t->subscriptionId, true, false, 201, null),
            $targets,
        );

        $test = $this;
        $this->app->instance(WebPushMessenger::class, new class($test) extends WebPushMessenger
        {
            public function __construct(private readonly PushNotificationDriverTest $test) {}

            public function isConfigured(): bool
            {
                return $this->test->configured();
            }

            public function send(array $targets, string $payload, int $ttl, string $urgency): array
            {
                return $this->test->record($targets, $payload, $ttl, $urgency);
            }
        });
    }

    public function configured(): bool
    {
        return $this->configured;
    }

    /**
     * @param  list<WebPushTarget>  $targets
     * @return list<WebPushOutcome>
     */
    public function record(array $targets, string $payload, int $ttl, string $urgency): array
    {
        $this->sent = compact('targets', 'payload', 'ttl', 'urgency');

        return ($this->respond)($targets);
    }

    private function deliveryFor(User $user, NotificationPriority $priority = NotificationPriority::Critical, array $payload = ['incident_id' => 42]): RenderedNotification
    {
        $notification = Notification::factory()->create([
            'team_id' => $this->team->id,
            'priority' => $priority,
            'payload_json' => $payload,
        ]);
        $delivery = NotificationDelivery::factory()->create([
            'team_id' => $this->team->id,
            'notification_id' => $notification->id,
            'notification_channel_id' => $this->channel->id,
        ]);

        return (new RenderedNotification(
            channelType: ChannelType::Push,
            address: (string) $user->id,
            subject: 'Botón de pánico en la unidad 12',
            body: 'SAM: Botón de pánico en la unidad 12.',
            variables: $payload,
        ))->forDelivery($delivery->id, false);
    }

    private function send(RenderedNotification $rendered): \App\Domains\Notifications\Data\DeliveryResult
    {
        return app(PushNotificationDriver::class)->send($rendered, $this->channel);
    }

    public function test_sends_to_every_subscription_of_the_user_in_the_team(): void
    {
        PushSubscription::factory()->count(2)->forMember($this->user, $this->team)->create();

        $result = $this->send($this->deliveryFor($this->user));

        $this->assertTrue($result->success);
        $this->assertCount(2, $this->sent['targets']);
        $this->assertSame('high', $this->sent['urgency']);
        $this->assertSame(3600, $this->sent['ttl']);

        $payload = json_decode($this->sent['payload'], true);
        $this->assertSame('Botón de pánico en la unidad 12', $payload['title']);
        $this->assertSame('SAM: Botón de pánico en la unidad 12.', $payload['body']);
        $this->assertSame('incident-42', $payload['tag']);
        $this->assertTrue($payload['critical']);
        $this->assertTrue($payload['renotify']);
        $this->assertStringEndsWith('/'.$this->team->slug.'/incidents/42', $payload['url']);

        $this->assertNotNull(PushSubscription::withoutGlobalScopes()->first()->last_used_at);
        $this->assertSystemLogged('notifications.push.sent', fn (array $c) => $c['result']['successes'] === 2);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_non_critical_uses_normal_urgency_and_longer_ttl(): void
    {
        PushSubscription::factory()->forMember($this->user, $this->team)->create();

        $this->send($this->deliveryFor($this->user, NotificationPriority::High));

        $this->assertSame('normal', $this->sent['urgency']);
        $this->assertSame(86400, $this->sent['ttl']);
        $this->assertFalse(json_decode($this->sent['payload'], true)['critical']);
    }

    public function test_without_incident_it_links_to_the_notification(): void
    {
        PushSubscription::factory()->forMember($this->user, $this->team)->create();

        $this->send($this->deliveryFor($this->user, NotificationPriority::High, []));

        $payload = json_decode($this->sent['payload'], true);
        $this->assertStringStartsWith('notification-', $payload['tag']);
        $this->assertStringContainsString('/notifications/', $payload['url']);
    }

    public function test_prunes_expired_subscriptions_and_succeeds_with_the_rest(): void
    {
        $good = PushSubscription::factory()->forMember($this->user, $this->team)->create();
        $gone = PushSubscription::factory()->forMember($this->user, $this->team)->create();
        $this->respond = fn (array $targets): array => array_map(
            fn (WebPushTarget $t) => $t->subscriptionId === $gone->id
                ? new WebPushOutcome($t->subscriptionId, false, true, 410, 'Gone')
                : new WebPushOutcome($t->subscriptionId, true, false, 201, null),
            $targets,
        );

        $result = $this->send($this->deliveryFor($this->user));

        $this->assertTrue($result->success);
        $this->assertNotNull(PushSubscription::withoutGlobalScopes()->find($good->id));
        $this->assertNull(PushSubscription::withoutGlobalScopes()->find($gone->id));
        $this->assertSystemLogged('notifications.push.subscription_pruned', fn (array $c) => $c['result']['status_code'] === 410);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_fails_when_every_subscription_fails(): void
    {
        PushSubscription::factory()->forMember($this->user, $this->team)->create();
        $this->respond = fn (array $targets): array => array_map(
            fn (WebPushTarget $t) => new WebPushOutcome($t->subscriptionId, false, false, 500, 'Server error'),
            $targets,
        );

        $result = $this->send($this->deliveryFor($this->user));

        $this->assertFalse($result->success);
        $this->assertSystemLogged('notifications.push.failed', fn (array $c) => $c['reason'] === 'all_failed');
    }

    public function test_fails_without_subscriptions(): void
    {
        $result = $this->send($this->deliveryFor($this->user));

        $this->assertFalse($result->success);
        $this->assertNull($this->sent);
        $this->assertSystemLogged('notifications.push.failed', fn (array $c) => $c['reason'] === 'no_subscriptions');
    }

    public function test_does_not_send_to_the_users_subscription_in_another_team(): void
    {
        $otherTeam = Team::factory()->create();
        $otherTeam->members()->attach($this->user, ['role' => 'member']);
        PushSubscription::factory()->forMember($this->user, $otherTeam)->create();

        $result = $this->assertNoTenantLeak($this->team, fn () => $this->send($this->deliveryFor($this->user)));

        $this->assertFalse($result->success);
        $this->assertNull($this->sent);
    }

    public function test_fails_when_user_is_no_longer_a_member(): void
    {
        $outsider = User::factory()->create();
        PushSubscription::factory()->forMember($outsider, $this->team)->create();

        $result = $this->send($this->deliveryFor($outsider));

        $this->assertFalse($result->success);
        $this->assertNull($this->sent);
        $this->assertSystemLogged('notifications.push.failed', fn (array $c) => $c['reason'] === 'not_member');
    }

    public function test_fails_for_a_non_numeric_address(): void
    {
        $rendered = new RenderedNotification(ChannelType::Push, 'ana@example.com', 'x', 'y');

        $result = $this->send($rendered);

        $this->assertFalse($result->success);
        $this->assertTrue($result->permanent);
    }

    public function test_fails_degraded_when_vapid_is_not_configured(): void
    {
        $this->configured = false;
        PushSubscription::factory()->forMember($this->user, $this->team)->create();

        $result = $this->send($this->deliveryFor($this->user));

        $this->assertFalse($result->success);
        $this->assertTrue($result->permanent);
        $this->assertNull($this->sent);
        $this->assertSystemLogged('notifications.push.failed', fn (array $c) => $c['reason'] === 'not_configured');
    }
}
```

> Antes de correr: confirmar los nombres de columnas de `NotificationDelivery` (`notification_channel_id` u otro) y del factory de `Notification` (`priority`, `payload_json`) leyendo `app/Domains/Notifications/Models/NotificationDelivery.php` y su factory; ajustar el helper `deliveryFor` a los nombres reales. Confirmar también si `DeliveryResult::failure()` acepta `permanent:` (ver `app/Domains/Notifications/Data/DeliveryResult.php`); si existe `DeliveryResult::permanentFailure()` o similar, usar ese en el driver.

- [ ] **Step 2: Correr y verificar que falla**

Run: `php artisan test --compact --filter=PushNotificationDriverTest`
Expected: FAIL (driver devuelve `push_not_implemented`).

- [ ] **Step 3: `PushPayload`**

```php
<?php

namespace App\Domains\Notifications\Support;

use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Models\Notification;
use App\Models\Team;

/**
 * Lo que el service worker (public/sw.js) recibe: título, cuerpo, a dónde
 * abrir y cómo agrupar. `tag` por incidente hace que cada nivel de la
 * escalera reemplace al anterior y `renotify` que vuelva a sonar/vibrar.
 * Los servicios de push aceptan ~4 KB: el cuerpo se recorta.
 */
final class PushPayload
{
    private const MAX_BODY = 1000;

    public static function build(Notification $notification, Team $team, string $subject, string $body): string
    {
        $incidentId = $notification->payload_json['incident_id'] ?? null;
        $hasIncident = is_int($incidentId) || (is_string($incidentId) && ctype_digit($incidentId));

        $url = $hasIncident
            ? route('incidents.show', ['current_team' => $team->slug, 'incident' => (int) $incidentId])
            : route('notifications.show', ['current_team' => $team->slug, 'notification' => $notification->id]);

        return (string) json_encode([
            'title' => $subject,
            'body' => mb_strimwidth($body, 0, self::MAX_BODY, '…'),
            'url' => $url,
            'tag' => $hasIncident ? 'incident-'.(int) $incidentId : 'notification-'.$notification->id,
            'critical' => $notification->priority === NotificationPriority::Critical,
            'renotify' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
```

> Confirmar el nombre de los parámetros de ruta de `notifications.show` con `php artisan route:list --name=notifications.show` y ajustar la llave (`notification`).

- [ ] **Step 4: Reescribir el driver**

```php
<?php

namespace App\Domains\Notifications\Channels;

use App\Contracts\Notifications\NotificationDriver;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Data\WebPushOutcome;
use App\Domains\Notifications\Data\WebPushTarget;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\PushSubscription;
use App\Domains\Notifications\Support\PushPayload;
use App\Models\Team;
use App\Models\User;
use App\Support\SafeErrorMessage;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Throwable;

/**
 * Avisos al dispositivo vía Web Push (VAPID). `address` es el id del usuario
 * (NotificationRecipient::addressForChannel) y el team sale de la fila de
 * entrega: sólo se manda a las suscripciones de ese usuario EN ese team, y
 * sólo si sigue siendo miembro. Es un canal gratuito que no interrumpe: no
 * sustituye la llamada ni frena su reintento.
 */
class PushNotificationDriver implements NotificationDriver
{
    private const CRITICAL_TTL = 3600;

    private const DEFAULT_TTL = 86400;

    public function __construct(
        private readonly WebPushMessenger $messenger,
    ) {}

    public function send(RenderedNotification $notification, NotificationChannel $channel): DeliveryResult
    {
        $input = ['delivery_id' => $notification->deliveryId];

        if (! ctype_digit($notification->address)) {
            return $this->fail('invalid_address', $input, permanent: true);
        }

        $userId = (int) $notification->address;
        $input['user_id'] = $userId;

        if (! $this->messenger->isConfigured()) {
            return $this->fail('not_configured', $input, permanent: true);
        }

        $delivery = $notification->deliveryId !== null
            ? NotificationDelivery::withoutGlobalScopes()->with('notification')->find($notification->deliveryId)
            : null;

        if ($delivery === null || $delivery->notification === null) {
            return $this->fail('no_delivery', $input, permanent: true);
        }

        $teamId = $delivery->team_id;
        $input['team_id'] = $teamId;
        $team = Team::query()->find($teamId);
        $user = User::query()->find($userId);

        if ($team === null || $user === null || ! $user->belongsToTeam($team)) {
            return $this->fail('not_member', $input, permanent: true);
        }

        $subscriptions = TenantContext::for($teamId, fn () => PushSubscription::query()
            ->where('team_id', $teamId)
            ->where('user_id', $userId)
            ->get());

        if ($subscriptions->isEmpty()) {
            return $this->fail('no_subscriptions', $input, permanent: true);
        }

        $critical = $delivery->notification->priority === NotificationPriority::Critical;
        $payload = PushPayload::build($delivery->notification, $team, (string) $notification->subject, $notification->body);

        $targets = $subscriptions
            ->map(fn (PushSubscription $s) => new WebPushTarget($s->id, $s->endpoint, $s->public_key, $s->auth_token, $s->content_encoding))
            ->values()
            ->all();

        try {
            $outcomes = $this->messenger->send(
                $targets,
                $payload,
                $critical ? self::CRITICAL_TTL : self::DEFAULT_TTL,
                $critical ? 'high' : 'normal',
            );
        } catch (Throwable $e) {
            SystemLog::failed('notifications.push.failed', reason: 'provider_error', input: $input, error: $e);

            return DeliveryResult::failure('webpush error: '.SafeErrorMessage::from($e), ['driver' => 'push']);
        }

        $this->recordOutcomes($teamId, $outcomes, $input);

        $successes = count(array_filter($outcomes, fn (WebPushOutcome $o) => $o->success));
        $result = ['subscriptions' => count($targets), 'successes' => $successes, 'failures' => count($outcomes) - $successes];

        if ($successes === 0) {
            SystemLog::failed('notifications.push.failed', reason: 'all_failed', input: $input, result: $result);

            return DeliveryResult::failure('webpush: all deliveries failed', ['driver' => 'push', ...$result]);
        }

        SystemLog::ok('notifications.push.sent', input: $input, calc: ['critical' => $critical], result: $result);

        return DeliveryResult::success(
            providerMessageId: 'webpush-'.$notification->deliveryId,
            response: ['driver' => 'push', ...$result],
        );
    }

    /**
     * @param  list<WebPushOutcome>  $outcomes
     * @param  array<string, mixed>  $input
     */
    private function recordOutcomes(int $teamId, array $outcomes, array $input): void
    {
        foreach ($outcomes as $outcome) {
            if ($outcome->expired) {
                PushSubscription::withoutGlobalScopes()
                    ->where('team_id', $teamId)
                    ->whereKey($outcome->subscriptionId)
                    ->delete();

                SystemLog::ok('notifications.push.subscription_pruned', input: [...$input, 'subscription_id' => $outcome->subscriptionId], result: ['status_code' => $outcome->statusCode]);
            }
        }

        $delivered = array_map(fn (WebPushOutcome $o) => $o->subscriptionId, array_filter($outcomes, fn (WebPushOutcome $o) => $o->success));

        if ($delivered !== []) {
            PushSubscription::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->whereKey($delivered)
                ->update(['last_used_at' => now()]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function fail(string $reason, array $input, bool $permanent): DeliveryResult
    {
        SystemLog::failed('notifications.push.failed', reason: $reason, input: $input);

        return new DeliveryResult(success: false, errorMessage: 'push_'.$reason, response: ['driver' => 'push'], permanent: $permanent);
    }
}
```

> `withoutGlobalScopes()` aquí va siempre acompañado de `where('team_id', $teamId)` explícito (patrón 4 de `app/CLAUDE.md`): el driver corre dentro del `TenantContext` del job, pero se filtra de más. Si `phpstan` marca `belongsToTeam` o el tipo de `$delivery->notification`, arreglar los tipos (no ignorar).

- [ ] **Step 5: Documentar códigos en `docs/SAM/logging.md`**

En la sección de notificaciones (≈ línea 508), agregar filas con el formato de la tabla existente:

| código | resultado | cuándo | reason |
|---|---|---|---|
| `notifications.push.sent` | ok | al menos un dispositivo aceptó el aviso | — |
| `notifications.push.failed` | failed | ningún dispositivo lo recibió | `invalid_address`, `not_configured`, `no_delivery`, `not_member`, `no_subscriptions`, `provider_error`, `all_failed` |
| `notifications.push.subscription_pruned` | ok | el servicio de push dijo 404/410 y se borró la suscripción | — |

- [ ] **Step 6: Correr**

Run: `php artisan test --compact --filter=PushNotificationDriverTest`
Expected: PASS (10 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Domains/Notifications/Channels/PushNotificationDriver.php app/Domains/Notifications/Support/PushPayload.php tests/Feature/Domains/Notifications/Drivers/PushNotificationDriverTest.php docs/SAM/logging.md
git commit -m "feat: driver de avisos al dispositivo con web push vapid"
```

---

### Task 5: Dirección del canal, canal de plataforma y enrutamiento real

**Files:**
- Modify: `app/Domains/Notifications/Models/NotificationRecipient.php:40-47`, `database/seeders/PlatformChannelSeeder.php`, `app/Domains/Notifications/Enums/ChannelType.php:40`, `app/Domains/Notifications/Support/ProvidedChannels.php` (docblock)
- Create: `database/migrations/2026_10_08_100100_seed_platform_push_channel.php`
- Test: `tests/Feature/Domains/Notifications/RecipientChannelAddressTest.php`, `tests/Feature/Domains/Notifications/PlatformChannelSeederTest.php`, `tests/Feature/Domains/Notifications/PushChannelRoutingTest.php`

**Interfaces:**
- Consumes: driver (Task 4).
- Produces: `addressForChannel(ChannelType::Push)` = `recipient_reference_id` para `RecipientType::User`, `null` para el resto; canal de plataforma `sam_push` activo; etiqueta `ChannelType::Push->label()` = `'Avisos al dispositivo'`.

- [ ] **Step 1: Tests que fallan**

En `RecipientChannelAddressTest.php` agregar:

```php
    public function test_push_address_is_the_user_id_for_user_recipients(): void
    {
        $recipient = NotificationRecipient::factory()->make([
            'recipient_type' => RecipientType::User,
            'recipient_reference_id' => '17',
            'address' => 'ana@example.com',
        ]);

        $this->assertSame('17', $recipient->addressForChannel(ChannelType::Push));
    }

    public function test_push_has_no_address_for_external_contacts(): void
    {
        $recipient = NotificationRecipient::factory()->make([
            'recipient_type' => RecipientType::ExternalContact,
            'recipient_reference_id' => null,
            'address' => 'externo@example.com',
        ]);

        $this->assertNull($recipient->addressForChannel(ChannelType::Push));
    }
```
(importar `RecipientType` si falta).

En `PlatformChannelSeederTest.php`: añadir `'push'` a la lista de `test_seeds_the_platform_channels_sam_operates` y cambiar el conteo esperado de `test_is_idempotent` de `5` a `6`.

`PushChannelRoutingTest.php` (nuevo) — usa los fixtures de `OnCallFirstRoutingTest` (leerlo y copiar `setUp`, `member()`, `scheduleOnCall()`, `incident()`):

```php
<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Access\Models\Role;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Actions\NotifyEscalationLevel;
use App\Domains\Notifications\Channels\WebPushMessenger;
use App\Domains\Notifications\Data\WebPushOutcome;
use App\Domains\Notifications\Data\WebPushTarget;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\PushSubscription;
use App\Domains\Notifications\Support\DeliveryEscalationGuard;
use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Database\Seeders\NotificationMeterSeeder;
use Database\Seeders\PlatformChannelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * El canal push ya estaba en la política crítica y en la escalera por
 * defecto; esto fija que, con un dispositivo suscrito, la escalera real
 * produce una entrega push al de turno y que push no frena lo pagado.
 */
class PushChannelRoutingTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private Team $team;

    private User $onCall;

    /** @var list<list<WebPushTarget>> */
    private array $pushed = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);
        $this->seed(NotificationMeterSeeder::class);
        $this->seed(PlatformChannelSeeder::class);
        Cache::flush();

        $owner = User::factory()->withVerifiedPhone('+5215510000001')->create();
        $this->team = $owner->currentTeam;
        $this->onCall = User::factory()->withVerifiedPhone('+5215510000003')->create();
        $this->team->members()->attach($this->onCall, ['role' => 'member']);
        Membership::query()->where('team_id', $this->team->id)->where('user_id', $this->onCall->id)
            ->update(['role_id' => Role::query()->where('code', 'monitorista')->firstOrFail()->id]);
        TenantScheduleProfile::factory()->create([
            'team_id' => $this->team->id,
            'is_active' => true,
            'shift_rules_json' => ['on_call' => [['user_id' => $this->onCall->id]]],
        ]);

        $test = $this;
        $this->app->instance(WebPushMessenger::class, new class($test) extends WebPushMessenger
        {
            public function __construct(private readonly PushChannelRoutingTest $test) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function send(array $targets, string $payload, int $ttl, string $urgency): array
            {
                return $this->test->record($targets);
            }
        });
    }

    /**
     * @param  list<WebPushTarget>  $targets
     * @return list<WebPushOutcome>
     */
    public function record(array $targets): array
    {
        $this->pushed[] = $targets;

        return array_map(fn (WebPushTarget $t) => new WebPushOutcome($t->subscriptionId, true, false, 201, null), $targets);
    }

    private function criticalIncident(): Incident
    {
        $priority = IncidentPriority::query()->where('code', 'critical')->firstOrFail();

        return Incident::factory()->open()->create(['team_id' => $this->team->id, 'incident_priority_id' => $priority->id]);
    }

    public function test_the_escalation_ladder_reaches_the_on_call_device(): void
    {
        PushSubscription::factory()->forMember($this->onCall, $this->team)->create();
        $incident = $this->criticalIncident();

        $this->assertNoTenantLeak($this->team, fn () => app(NotifyEscalationLevel::class)->execute(
            incident: $incident,
            level: 0,
            eventKey: "push_routing:{$incident->id}:0",
            notificationType: 'incident.sla_breached',
            subject: 'Nadie lo ha atendido',
            body: 'SAM: nadie lo ha atendido.',
        ));

        $push = NotificationDelivery::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->whereHas('channel', fn ($q) => $q->where('channel_type', ChannelType::Push->value))
            ->sole();

        $this->assertSame(DeliveryStatus::Delivered, $push->status);
        $this->assertCount(1, $this->pushed);
        $this->assertSystemLogged('notifications.push.sent');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_delivered_push_does_not_count_as_reaching_the_recipient(): void
    {
        PushSubscription::factory()->forMember($this->onCall, $this->team)->create();
        $incident = $this->criticalIncident();

        app(NotifyEscalationLevel::class)->execute(
            incident: $incident,
            level: 0,
            eventKey: "push_guard:{$incident->id}:0",
            notificationType: 'incident.sla_breached',
            subject: 'Nadie lo ha atendido',
            body: 'SAM: nadie lo ha atendido.',
        );

        $sms = NotificationDelivery::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->whereHas('channel', fn ($q) => $q->where('channel_type', ChannelType::Sms->value))
            ->first();

        $this->assertNotNull($sms, 'La escalera por defecto debe incluir SMS en el nivel 0 de un crítico');

        $verdict = app(DeliveryEscalationGuard::class)->check($sms);

        $this->assertNotSame('recipient_reached', $verdict['reason']);
    }
}
```

> Antes de correr: leer `NotifyEscalationLevel::execute` y `DeliveryEscalationGuard` para confirmar (a) si el despacho es síncrono en tests (si usa `SendNotificationJob`, correr con la cola `sync` por defecto de testing o llamar `Bus::dispatchSync`), (b) el nombre público del método del guard que devuelve `['reason' => ..., 'calc' => ...]` (`check`, `evaluate`, …) y (c) si el paso 0 por defecto incluye SMS; si no, buscar el canal pagado del paso y ajustar el `where`. El test fija el comportamiento existente; si ya pasa sin cambios, es correcto (protege contra regresión).

- [ ] **Step 2: Correr y verificar que fallan**

Run: `php artisan test --compact --filter='RecipientChannelAddressTest|PlatformChannelSeederTest|PushChannelRoutingTest'`
Expected: FAIL (push devuelve el email; seeder sin push; escalera sin entrega push).

- [ ] **Step 3: Implementar**

`NotificationRecipient::addressForChannel`:

```php
    /**
     * The destination to use for a given channel: telephony channels need a
     * phone, mail needs an email (falling back to the legacy address), push
     * needs the SAM user id (sólo usuarios tienen dispositivos suscritos) and
     * everything else keeps using the legacy address.
     */
    public function addressForChannel(ChannelType $channelType): ?string
    {
        return match ($channelType) {
            ChannelType::Sms, ChannelType::Voice, ChannelType::Whatsapp => self::presentOrNull($this->phone),
            ChannelType::Email => self::presentOrNull($this->email) ?? self::presentOrNull($this->address),
            ChannelType::Push => $this->recipient_type === RecipientType::User
                ? self::presentOrNull($this->recipient_reference_id)
                : null,
            default => self::presentOrNull($this->address),
        };
    }
```
(importar `App\Domains\Notifications\Enums\RecipientType`).

`PlatformChannelSeeder`: añadir a `$channels`

```php
            ['code' => 'sam_push', 'name' => 'Avisos al dispositivo', 'provider' => 'webpush', 'channel_type' => ChannelType::Push],
```

Migración `2026_10_08_100100_seed_platform_push_channel.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Activa el canal de plataforma de avisos al dispositivo en entornos ya
 * sembrados (el seeder sólo corre en instalaciones nuevas). Idempotente y sin
 * pisar una fila existente, igual que PlatformChannelSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('notification_channels')
            ->where('code', 'sam_push')
            ->orWhere('channel_type', 'push')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('notification_channels')->insert([
            'code' => 'sam_push',
            'name' => 'Avisos al dispositivo',
            'provider' => 'webpush',
            'channel_type' => 'push',
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
        DB::table('notification_channels')->where('code', 'sam_push')->delete();
    }
};
```

> Revisar las columnas reales de `notification_channels` (migración de creación) por si hay otras NOT NULL sin default; incluirlas.

`ChannelType::label()`: `self::Push => 'Avisos al dispositivo',`. Buscar tests que esperen `'Push'`: `grep -rn "'Push'" tests resources/js` y actualizar.

`ProvidedChannels` docblock: cambiar "Push, Slack o Webhook existen…" por "Slack o Webhook existen…".

- [ ] **Step 4: Correr**

Run: `php artisan test --compact --filter='RecipientChannelAddressTest|PlatformChannelSeederTest|PushChannelRoutingTest|ChannelAwareDispatchTest|OnCallFirstRoutingTest|RetryAndFallbackTest|DeliveryHardeningTest'`
Expected: PASS. Si algún test existente de enrutamiento cuenta entregas totales y ahora ve una de más por push, revisar: con `PlatformChannelSeeder` sembrado y sin suscripciones, push se registra como entrega fallida `push_no_subscriptions`. Ajustar sólo si el test cuenta "todas las entregas" sin filtrar canal, y anotar en el commit por qué.

- [ ] **Step 5: Commit**

```bash
git add app/Domains/Notifications database tests
git commit -m "feat: el canal push llega al usuario y se activa como canal de plataforma"
```

---

### Task 6: Endpoints de suscripción y llave pública compartida

**Files:**
- Create: `app/Http/Controllers/Settings/PushSubscriptionController.php`, `app/Http/Requests/Settings/StorePushSubscriptionRequest.php`
- Modify: `routes/settings.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/types/global.d.ts`, `docs/SAM/logging.md`
- Test: `tests/Feature/Settings/PushSubscriptionControllerTest.php`

**Interfaces:**
- Consumes: `PushSubscription` (Task 2), `WebPushMessenger::isConfigured()` (Task 3).
- Produces: rutas `push-subscriptions.store` (`POST settings/push-subscriptions`, body `{endpoint, keys: {p256dh, auth}, content_encoding?}` → `201 {id}`/`200 {id}`) y `push-subscriptions.destroy` (`DELETE settings/push-subscriptions`, body `{endpoint}` → `204`); prop Inertia compartida `webPush: { publicKey: string | null }`; logs `notifications.push_subscription.registered` / `.removed`.

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Settings;

use App\Domains\Notifications\Models\PushSubscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Minishlink\WebPush\VAPID;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class PushSubscriptionControllerTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/device-1';

    private function body(string $endpoint = self::ENDPOINT): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
                'auth' => 'tBHItJI5svbpez7KI4CCXg',
            ],
        ];
    }

    public function test_registers_the_device_for_the_user_in_the_current_team(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1')
            ->postJson(route('push-subscriptions.store'), $this->body())
            ->assertCreated();

        $subscription = PushSubscription::withoutGlobalScopes()->sole();
        $this->assertSame($user->id, $subscription->user_id);
        $this->assertSame($user->currentTeam->id, $subscription->team_id);
        $this->assertSame('iPhone', $subscription->device_label);
        $this->assertSystemLogged('notifications.push_subscription.registered', fn (array $c) => $c['calc']['created'] === true);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_registering_the_same_device_twice_keeps_one_row(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('push-subscriptions.store'), $this->body())->assertCreated();
        $this->actingAs($user)->postJson(route('push-subscriptions.store'), $this->body())->assertOk();

        $this->assertSame(1, PushSubscription::withoutGlobalScopes()->count());
    }

    public function test_same_endpoint_moves_to_the_new_user_and_team(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice)->postJson(route('push-subscriptions.store'), $this->body())->assertCreated();
        $this->actingAs($bob)->postJson(route('push-subscriptions.store'), $this->body())->assertOk();

        $subscription = PushSubscription::withoutGlobalScopes()->sole();
        $this->assertSame($bob->id, $subscription->user_id);
        $this->assertSame($bob->currentTeam->id, $subscription->team_id);
        $this->assertSystemLogged('notifications.push_subscription.registered', fn (array $c) => $c['calc']['moved'] === true);
    }

    public function test_validates_the_subscription(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('push-subscriptions.store'), ['endpoint' => 'http://insecure.example.com/x', 'keys' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
    }

    public function test_removes_only_the_users_own_device(): void
    {
        $user = User::factory()->create();
        PushSubscription::factory()->forMember($user, $user->currentTeam)->create(['endpoint' => self::ENDPOINT]);

        $this->actingAs($user)
            ->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => self::ENDPOINT])
            ->assertNoContent();

        $this->assertSame(0, PushSubscription::withoutGlobalScopes()->count());
        $this->assertSystemLogged('notifications.push_subscription.removed');
    }

    public function test_cannot_remove_another_tenants_device(): void
    {
        $victim = User::factory()->create();
        $attacker = User::factory()->create();
        PushSubscription::factory()->forMember($victim, $victim->currentTeam)->create(['endpoint' => self::ENDPOINT]);

        $this->assertNoTenantLeak($attacker->currentTeam, function () use ($attacker) {
            $this->actingAs($attacker)
                ->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => self::ENDPOINT])
                ->assertNoContent();
        });

        $this->assertSame(1, PushSubscription::withoutGlobalScopes()->count());
    }

    public function test_guests_cannot_register(): void
    {
        $this->postJson(route('push-subscriptions.store'), $this->body())->assertUnauthorized();
    }

    public function test_shares_the_public_key_when_configured(): void
    {
        $keys = VAPID::createVapidKeys();
        config(['webpush.vapid' => ['subject' => 'mailto:x@example.com', 'public_key' => $keys['publicKey'], 'private_key' => $keys['privateKey']]]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('notification-preferences.edit'))
            ->assertInertia(fn ($page) => $page->where('webPush.publicKey', $keys['publicKey']));
    }

    public function test_shares_null_public_key_when_not_configured(): void
    {
        config(['webpush.vapid' => ['subject' => null, 'public_key' => null, 'private_key' => null]]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('notification-preferences.edit'))
            ->assertInertia(fn ($page) => $page->where('webPush.publicKey', null));
    }
}
```

> Si `notification-preferences.edit` exige permiso (`viewAny NotificationPreference`) que un usuario de factory no tiene, sembrar `AccessSeeder` en `setUp` como hacen los tests de settings vecinos (`NotificationPreferencesSettingsTest`).

> **Fuga en `store`:** el test `test_same_endpoint_moves_to_the_new_user_and_team` cambia a propósito una fila de otro team. No lo envolvemos en `assertNoTenantLeak`: es el comportamiento buscado (el dispositivo es de quien lo suscribe ahora). El riesgo real (que un atacante "robe" el dispositivo de otro) exige conocer el endpoint, que es un secreto del navegador y nunca sale del servidor (`$hidden`, no se loguea).

- [ ] **Step 2: Correr y verificar que falla**

Run: `php artisan test --compact --filter=PushSubscriptionControllerTest`
Expected: FAIL (ruta no existe).

- [ ] **Step 3: Request, controlador, rutas, prop compartida**

`app/Http/Requests/Settings/StorePushSubscriptionRequest.php`:

```php
<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class StorePushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'url:https', 'max:2048'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
        ];
    }
}
```

`app/Http/Controllers/Settings/PushSubscriptionController.php`:

```php
<?php

namespace App\Http\Controllers\Settings;

use App\Domains\Notifications\Models\PushSubscription;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StorePushSubscriptionRequest;
use App\Models\User;
use App\Support\SystemLog;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Alta y baja del dispositivo actual para avisos de SAM. Un endpoint es una
 * sola fila: volver a registrarlo la reasigna al usuario y team actuales
 * (navegador compartido o cambio de team), así el dueño anterior deja de
 * recibir ahí.
 */
class PushSubscriptionController extends Controller
{
    public function store(StorePushSubscriptionRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $team = currentTeam();
        abort_if($team === null, 403);

        $endpoint = (string) $request->validated('endpoint');

        // Buscamos fuera del scope a propósito: el mismo navegador pudo
        // suscribirse antes con otro usuario o team y debe pasar a este.
        $subscription = PushSubscription::withoutGlobalScopes()
            ->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))
            ->first();

        $created = $subscription === null;
        $moved = ! $created && ($subscription->user_id !== $user->id || $subscription->team_id !== $team->id);

        $subscription ??= new PushSubscription;
        $subscription->fill([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'public_key' => (string) $request->validated('keys.p256dh'),
            'auth_token' => (string) $request->validated('keys.auth'),
            'content_encoding' => (string) ($request->validated('content_encoding') ?? 'aes128gcm'),
            'device_label' => self::deviceLabel((string) $request->userAgent()),
        ])->save();

        SystemLog::ok(
            'notifications.push_subscription.registered',
            input: ['team_id' => $team->id, 'user_id' => $user->id, 'subscription_id' => $subscription->id],
            calc: ['created' => $created, 'moved' => $moved],
        );

        return response()->json(['id' => $subscription->id], $created ? 201 : 200);
    }

    public function destroy(Request $request, #[CurrentUser] User $user): Response
    {
        $validated = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);
        $team = currentTeam();
        abort_if($team === null, 403);

        $deleted = PushSubscription::query()
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('endpoint_hash', PushSubscription::hashEndpoint($validated['endpoint']))
            ->delete();

        SystemLog::ok(
            'notifications.push_subscription.removed',
            input: ['team_id' => $team->id, 'user_id' => $user->id],
            result: ['deleted' => $deleted],
        );

        return response()->noContent();
    }

    /**
     * Etiqueta humana del dispositivo para "Mis avisos"; nunca el user-agent
     * completo (huella del navegador).
     */
    private static function deviceLabel(string $userAgent): ?string
    {
        $device = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Macintosh') => 'Mac',
            str_contains($userAgent, 'Windows') => 'Windows',
            default => null,
        };

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };

        if ($device === 'iPhone' || $device === 'iPad') {
            return $device;
        }

        $parts = array_filter([$device, $browser]);

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
```

`routes/settings.php`, dentro del grupo `['auth', 'verified']` justo después de las rutas de `notification-preferences`:

```php
    // Dispositivo actual para avisos de SAM (Web Push), en el team actual.
    Route::post('settings/push-subscriptions', [PushSubscriptionController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('push-subscriptions.store');
    Route::delete('settings/push-subscriptions', [PushSubscriptionController::class, 'destroy'])
        ->name('push-subscriptions.destroy');
```
(import `use App\Http\Controllers\Settings\PushSubscriptionController;`).

`HandleInertiaRequests::share`, añadir junto a `copilot`:

```php
            // Llave pública VAPID para suscribir este dispositivo; null si la
            // plataforma no tiene avisos al dispositivo configurados.
            'webPush' => fn () => [
                'publicKey' => app(WebPushMessenger::class)->isConfigured()
                    ? (string) config('webpush.vapid.public_key')
                    : null,
            ],
```
(import `App\Domains\Notifications\Channels\WebPushMessenger`).

`resources/js/types/global.d.ts`, en las props compartidas junto a `copilot`:

```ts
            webPush: { publicKey: string | null };
```

`docs/SAM/logging.md`: añadir `notifications.push_subscription.registered` (ok; calc `created`, `moved`) y `notifications.push_subscription.removed` (ok; result `deleted`).

- [ ] **Step 4: Wayfinder y tests**

Run: `php artisan wayfinder:generate --with-form && php artisan test --compact --filter=PushSubscriptionControllerTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http routes/settings.php resources/js/types/global.d.ts tests/Feature/Settings/PushSubscriptionControllerTest.php docs/SAM/logging.md
git commit -m "feat: alta y baja del dispositivo para avisos de sam"
```

---

### Task 7: Aviso al asignado y push en la asignación de turno

**Files:**
- Create: `app/Domains/Notifications/Listeners/NotifyOnIncidentAssigned.php`
- Modify: `app/Domains/Notifications/NotificationsServiceProvider.php`, `app/Domains/Incidents/Support/IncidentNoticeCopy.php`, `app/Domains/Incidents/Listeners/AssignOnCallOnIncidentCreated.php:111,153`, `app/Domains/Notifications/Support/NotificationTypeLabels.php`, `app/Http/Controllers/Settings/NotificationPreferencesController.php:25-29`, `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/Incidents/NotifyOnIncidentAssignedTest.php`; ajustar el test existente que fija `forced_channel_types => ['web']` del aviso de turno (`grep -rn "incident_oncall_assigned\|forced_channel_types" tests`).

**Interfaces:**
- Consumes: `IncidentAssigned(Incident $incident, IncidentAssignment $assignment)`; `SendNotification::execute(...)` (misma firma que usa `AssignOnCallOnIncidentCreated::notifyAssignee`).
- Produces: tipo de aviso `incident.assigned`; `IncidentNoticeCopy::assigned(Incident): array{subject, body, spoken}`; logs `incidents.assignment.notified` (ok) / `incidents.assignment.notified` (skipped con reason `not_user|self_assigned|terminal|stale|on_call_already_notified|user_not_found`).

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Actions\AssignIncident;
use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Models\Notification;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class NotifyOnIncidentAssignedTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private Team $team;

    private User $owner;

    private User $assignee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);
        Bus::fake();

        $this->owner = User::factory()->create();
        $this->team = $this->owner->currentTeam;
        $this->assignee = User::factory()->create();
        $this->team->members()->attach($this->assignee, ['role' => 'member']);
    }

    private function incident(string $priority = 'high'): Incident
    {
        $p = IncidentPriority::query()->where('code', $priority)->firstOrFail();

        return Incident::factory()->open()->create(['team_id' => $this->team->id, 'incident_priority_id' => $p->id]);
    }

    private function assign(Incident $incident, User $to, ?User $by, ?string $role = null): void
    {
        app(AssignIncident::class)->execute(
            incident: $incident,
            assigneeType: AssigneeType::User,
            assigneeId: $to->id,
            role: $role,
            assignedByType: $by !== null ? IncidentCreatorType::User : IncidentCreatorType::System,
            assignedById: $by?->id,
        );
    }

    private function assignedNotices(): \Illuminate\Support\Collection
    {
        return Notification::withoutGlobalScopes()->where('notification_type', 'incident.assigned')->get();
    }

    public function test_a_manual_assignment_notifies_the_assignee_on_web_and_device(): void
    {
        $incident = $this->incident('high');

        $this->assertNoTenantLeak($this->team, fn () => $this->assign($incident, $this->assignee, $this->owner));

        $notice = $this->assignedNotices()->sole();
        $this->assertSame($this->team->id, $notice->team_id);
        $this->assertSame(NotificationPriority::High, $notice->priority);
        $this->assertSame(['web', 'push'], $notice->payload_json['force_channels']);
        $this->assertSame([(string) $this->assignee->id], array_column($notice->payload_json['recipients'], 'recipient_reference_id'));
        $this->assertSystemLogged('incidents.assignment.notified', fn (array $c) => ($c['outcome'] ?? 'ok') === 'ok');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_critical_incident_assignment_is_critical(): void
    {
        $this->assign($this->incident('critical'), $this->assignee, $this->owner);

        $this->assertSame(NotificationPriority::Critical, $this->assignedNotices()->sole()->priority);
    }

    public function test_assigning_to_yourself_does_not_notify(): void
    {
        $this->assign($this->incident(), $this->owner, $this->owner);

        $this->assertCount(0, $this->assignedNotices());
        $this->assertSystemLogged('incidents.assignment.notified', fn (array $c) => ($c['reason'] ?? null) === 'self_assigned');
    }

    public function test_on_call_assignment_is_not_duplicated(): void
    {
        $this->assign($this->incident('critical'), $this->assignee, null, 'on_call');

        $this->assertCount(0, $this->assignedNotices());
        $this->assertSystemLogged('incidents.assignment.notified', fn (array $c) => ($c['reason'] ?? null) === 'on_call_already_notified');
    }

    public function test_a_superseded_assignment_does_not_notify(): void
    {
        $incident = $this->incident();
        $other = User::factory()->create();
        $this->team->members()->attach($other, ['role' => 'member']);

        // Dos asignaciones seguidas: si la primera ya fue reemplazada cuando
        // corre su listener, no avisa.
        \Illuminate\Support\Facades\Event::fake([\App\Domains\Incidents\Events\IncidentAssigned::class]);
        $this->assign($incident, $this->assignee, $this->owner);
        $this->assign($incident, $other, $this->owner);
        $first = \App\Domains\Incidents\Models\IncidentAssignment::query()->where('assigned_to_id', $this->assignee->id)->sole();

        app(\App\Domains\Notifications\Listeners\NotifyOnIncidentAssigned::class)
            ->handle(new \App\Domains\Incidents\Events\IncidentAssigned($incident->fresh(), $first->fresh()));

        $this->assertCount(0, $this->assignedNotices());
        $this->assertSystemLogged('incidents.assignment.notified', fn (array $c) => ($c['reason'] ?? null) === 'stale');
    }
}
```

> Confirmar con `app/Support/SystemLog.php` cómo queda el outcome en el contexto capturado (`outcome`, `result`, …) y ajustar las closures de `assertSystemLogged` (por ejemplo `$c['reason'] ?? null`). Confirmar el namespace real de `AssigneeType`/`IncidentCreatorType` (`grep -rn "enum AssigneeType" app`). Si `Incident` no tiene estado terminal fácil de fabricar, añadir un test `terminal` con `Incident::factory()->closed()` si el factory lo ofrece.

- [ ] **Step 2: Correr y verificar que falla**

Run: `php artisan test --compact --filter=NotifyOnIncidentAssignedTest`
Expected: FAIL (no hay listener; 0 avisos).

- [ ] **Step 3: Copy, listener, registro**

`IncidentNoticeCopy`, después de `onCallAssigned`:

```php
    /**
     * Alguien del equipo te asignó un incidente.
     *
     * @return array{subject: string, body: string, spoken: string}
     */
    public static function assigned(Incident $incident): array
    {
        return [
            'subject' => 'Te asignaron: '.self::headline($incident, self::unit($incident), 'en la unidad'),
            'body' => 'SAM: Te asignaron esto: '.self::headline($incident, self::shortUnit($incident), 'en la unidad').'.',
            'spoken' => 'Hola, te llama SAM. Te asignaron una alerta de '.self::what($incident).self::spokenWhere($incident, 'en la unidad').'. Por favor revísala en SAM.',
        ];
    }
```

`app/Domains/Notifications/Listeners/NotifyOnIncidentAssigned.php`:

```php
<?php

namespace App\Domains\Notifications\Listeners;

use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Events\IncidentAssigned;
use App\Domains\Incidents\Models\IncidentAssignment;
use App\Domains\Incidents\Support\IncidentNoticeCopy;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Models\User;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Avisa a quien le asignaron un incidente (en la app y en su dispositivo).
 * Nunca a quien asignó, ni la asignación de guardia (ya avisa
 * AssignOnCallOnIncidentCreated). Corre tras el commit: AssignIncident
 * despacha el evento dentro de su transacción.
 */
class NotifyOnIncidentAssigned implements ShouldQueue
{
    public string $queue = 'notifications';

    public bool $afterCommit = true;

    public function __construct(
        private readonly SendNotification $sendNotification,
    ) {}

    public function handle(IncidentAssigned $event): void
    {
        $incident = $event->incident;
        $assignment = $event->assignment;

        TenantContext::for($incident->team_id, function () use ($incident, $assignment): void {
            $input = ['incident_id' => $incident->id, 'assignment_id' => $assignment->id];

            $reason = match (true) {
                $assignment->assigned_to_type !== AssigneeType::User => 'not_user',
                $assignment->role === 'on_call' => 'on_call_already_notified',
                $assignment->assigned_by_id !== null && $assignment->assigned_by_id === $assignment->assigned_to_id => 'self_assigned',
                $incident->isTerminal() => 'terminal',
                $this->isStale($assignment) => 'stale',
                default => null,
            };

            if ($reason !== null) {
                SystemLog::skipped('incidents.assignment.notified', reason: $reason, input: $input);

                return;
            }

            $user = User::query()->find($assignment->assigned_to_id);

            if ($user === null || ! $user->belongsToTeam($incident->team)) {
                SystemLog::skipped('incidents.assignment.notified', reason: 'user_not_found', input: $input);

                return;
            }

            $copy = IncidentNoticeCopy::assigned($incident);
            $priority = $incident->priority?->code === 'critical' ? NotificationPriority::Critical : NotificationPriority::High;

            $notification = $this->sendNotification->execute(
                teamId: $incident->team_id,
                notificationType: 'incident.assigned',
                sourceType: NotificationSourceType::Incident,
                sourceReferenceId: (string) $incident->id,
                priority: $priority,
                triggeredByType: $assignment->assigned_by_id !== null ? NotificationTriggeredByType::User : NotificationTriggeredByType::System,
                triggeredById: $assignment->assigned_by_id,
                eventKey: 'incident_assigned:'.$assignment->id,
                payload: [
                    'incident_id' => $incident->id,
                    'incident_reference' => $incident->reference(),
                    'incident_type' => $incident->type?->code,
                    'severity' => $incident->priority?->code,
                    'incident_title' => $incident->title,
                    'force_channels' => [ChannelType::Web->value, ChannelType::Push->value],
                    'recipients' => [[
                        'recipient_type' => 'user',
                        'address' => $user->email,
                        'name' => $user->name,
                        'recipient_reference_id' => (string) $user->id,
                    ]],
                ],
                subject: $copy['subject'],
                bodyPreview: $copy['body'],
            );

            SystemLog::ok('incidents.assignment.notified', input: $input, result: [
                'notification_id' => $notification->id,
                'priority' => $priority->value,
            ]);
        });
    }

    private function isStale(IncidentAssignment $assignment): bool
    {
        return IncidentAssignment::query()
            ->whereKey($assignment->id)
            ->whereNotNull('unassigned_at')
            ->exists();
    }
}
```

> Confirmar: `Incident::isTerminal()` (o el helper equivalente que usa `ClaimIncident`/`IncidentPolicy`), `NotificationTriggeredByType::User` existe, `IncidentAssignment` tiene `role`/`assigned_by_id`/`unassigned_at`, y si `IncidentAssignment` usa `BelongsToTenant` (si no tiene `team_id`, el query por id está bien). `SendNotification::execute` puede devolver `?Notification`; si es nullable, manejar `null` con `skipped` reason `deduplicated`.

`NotificationsServiceProvider::boot`:

```php
        Event::listen(IncidentAssigned::class, NotifyOnIncidentAssigned::class);
```
(imports `App\Domains\Incidents\Events\IncidentAssigned`, `App\Domains\Notifications\Listeners\NotifyOnIncidentAssigned`).

`AssignOnCallOnIncidentCreated`: en `notifyAssignee` cambiar `'force_channels' => [ChannelType::Web->value],` por `'force_channels' => [ChannelType::Web->value, ChannelType::Push->value],` y en el log `'forced_channel_types' => [ChannelType::Web->value, ChannelType::Push->value],`.

`NotificationTypeLabels`: añadir `'incident.assigned' => 'Incidente asignado a ti',` junto a `incident.assigned.on_call`.

`NotificationPreferencesController::BASE_TYPES`: añadir `'incident.assigned',`.

`docs/SAM/logging.md` (sección incidentes): `incidents.assignment.notified` — ok cuando se avisa al asignado; skipped con reasons `not_user`, `on_call_already_notified`, `self_assigned`, `terminal`, `stale`, `user_not_found`.

- [ ] **Step 4: Correr**

Run: `php artisan test --compact --filter='NotifyOnIncidentAssignedTest|AssignOnCall|IncidentAssignment|NotificationPreferencesSettingsTest|CrossDomainListenersTest'`
Expected: PASS (ajustando el test existente que fijaba `['web']` para el aviso de turno a `['web', 'push']`).

- [ ] **Step 5: Commit**

```bash
git add app docs/SAM/logging.md tests
git commit -m "feat: avisa al asignado de un incidente en la app y en su dispositivo"
```

---

### Task 8: Shell PWA — manifest, íconos y service worker

**Files:**
- Create: `public/manifest.webmanifest`, `public/sw.js`, `public/icons/icon-192.png`, `public/icons/icon-512.png`, `public/icons/icon-maskable-512.png`
- Modify: `resources/views/app.blade.php:35-37`
- Test: verificación en navegador (Step 5)

**Interfaces:**
- Produces: `/sw.js` con scope `/`, que espera payloads `{title, body, url, tag, critical, renotify}` (Task 4) y escucha `message` `{type: 'resubscribe-endpoint', url}` (no se usa en v1); `/manifest.webmanifest`.

- [ ] **Step 1: Íconos**

Generar desde `public/favicon.svg` con Chrome headless (sin dependencias nuevas). En el scratchpad:

```bash
SCRATCH=/private/tmp/claude-501/pwa-icons && mkdir -p $SCRATCH public/icons
for spec in "192 0" "512 0" "512 1"; do set -- $spec; size=$1; maskable=$2
  pad=$([ "$maskable" = 1 ] && echo 20 || echo 8)
  cat > $SCRATCH/icon-$size-$maskable.html <<EOF
<html><body style="margin:0;width:${size}px;height:${size}px;background:#0a0a0a;display:flex;align-items:center;justify-content:center">
<img src="file://$PWD/public/favicon.svg" style="width:$((100-2*pad))%;height:$((100-2*pad))%"></body></html>
EOF
  "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless --disable-gpu --hide-scrollbars --window-size=$size,$size --screenshot=$SCRATCH/out-$size-$maskable.png $SCRATCH/icon-$size-$maskable.html
done
cp $SCRATCH/out-192-0.png public/icons/icon-192.png
cp $SCRATCH/out-512-0.png public/icons/icon-512.png
cp $SCRATCH/out-512-1.png public/icons/icon-maskable-512.png
```

Abrir los tres PNG con Read y confirmar que el logo se ve centrado y legible. (Fondo `#0a0a0a`, el oscuro de marca; si el favicon es oscuro sobre transparente y no contrasta, usar fondo `#ffffff`.)

- [ ] **Step 2: Manifest**

`public/manifest.webmanifest`:

```json
{
    "name": "SAM",
    "short_name": "SAM",
    "description": "Monitoreo de tu flota y alertas en tiempo real",
    "lang": "es-MX",
    "start_url": "/",
    "scope": "/",
    "display": "standalone",
    "background_color": "#0a0a0a",
    "theme_color": "#0a0a0a",
    "icons": [
        { "src": "/icons/icon-192.png", "sizes": "192x192", "type": "image/png" },
        { "src": "/icons/icon-512.png", "sizes": "512x512", "type": "image/png" },
        { "src": "/icons/icon-maskable-512.png", "sizes": "512x512", "type": "image/png", "purpose": "maskable" }
    ]
}
```

- [ ] **Step 3: Blade**

En `resources/views/app.blade.php`, junto a los íconos (líneas 35-37):

```blade
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="theme-color" content="#0a0a0a">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="SAM">
```

- [ ] **Step 4: Service worker**

`public/sw.js`:

```js
/*
 * Service worker de SAM: sólo avisos al dispositivo. Sin caché offline a
 * propósito (no servir builds viejos). El servidor manda
 * { title, body, url, tag, critical, renotify } (PushPayload.php).
 */
const ICON = '/icons/icon-192.png';
const CRITICAL_VIBRATION = [400, 200, 400, 200, 800];

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let data = {};

    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { body: event.data ? event.data.text() : '' };
    }

    const title = data.title || 'SAM';
    const critical = data.critical === true;

    event.waitUntil(
        self.registration.showNotification(title, {
            body: data.body || '',
            tag: data.tag,
            renotify: Boolean(data.tag) && data.renotify !== false,
            icon: ICON,
            badge: ICON,
            requireInteraction: critical,
            vibrate: critical ? CRITICAL_VIBRATION : [200],
            data: { url: data.url || '/' },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(event.notification.data?.url || '/', self.location.origin).href;

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            for (const client of windows) {
                if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
                    return client.focus().then((focused) => (focused && 'navigate' in focused ? focused.navigate(target) : undefined));
                }
            }

            return self.clients.openWindow(target);
        }),
    );
});

self.addEventListener('pushsubscriptionchange', (event) => {
    // El navegador rotó la suscripción: la app la vuelve a sincronizar al
    // abrirse (lib/web-push.ts → syncSubscription). Sin sesión garantizada
    // aquí, no intentamos llamar al servidor.
    event.waitUntil(Promise.resolve());
});
```

- [ ] **Step 5: Verificación manual**

Con la app sirviendo (`composer run dev` o la receta de preview de `worktree-bootstrap`), en el navegador del panel:
1. `GET /manifest.webmanifest` → 200 y JSON válido.
2. `GET /sw.js` → 200, `Content-Type` JavaScript.
3. En consola: `navigator.serviceWorker.register('/sw.js').then(r => r.scope)` → `"<origin>/"`.

- [ ] **Step 6: Commit**

```bash
git add public/manifest.webmanifest public/sw.js public/icons resources/views/app.blade.php
git commit -m "feat: sam instalable como pwa con service worker de avisos"
```

---

### Task 9: Activar avisos desde el navegador (lib, "Mis avisos", banner)

**Files:**
- Create: `resources/js/lib/web-push.ts`, `resources/js/hooks/use-web-push.ts`, `resources/js/components/sam/settings/device-push-card.tsx`, `resources/js/components/push-opt-in-banner.tsx`
- Modify: `resources/js/pages/settings/notifications.tsx`, `resources/js/layouts/ops-layout.tsx`

**Interfaces:**
- Consumes: rutas Wayfinder `@/routes/push-subscriptions` (`store`, `destroy`) de Task 6; prop `webPush.publicKey`; `/sw.js` (Task 8).
- Produces:
  - `lib/web-push.ts`: `type PushSupport = 'unsupported' | 'needs-install' | 'supported'`; `pushSupport(): PushSupport`; `registerServiceWorker(): Promise<ServiceWorkerRegistration | null>`; `currentSubscription(): Promise<PushSubscription | null>`; `subscribeDevice(publicKey: string): Promise<boolean>`; `unsubscribeDevice(): Promise<void>`; `syncSubscription(): Promise<void>`.
  - `hooks/use-web-push.ts`: `useWebPush(): { status: 'unsupported' | 'needs-install' | 'unconfigured' | 'blocked' | 'off' | 'on' | 'loading'; enable(): Promise<void>; disable(): Promise<void>; busy: boolean }`.

- [ ] **Step 1: `lib/web-push.ts`**

```ts
import { deleteJson, postJson } from '@/lib/sam-fetch';
import {
    destroy as destroySubscription,
    store as storeSubscription,
} from '@/routes/push-subscriptions';

export type PushSupport = 'unsupported' | 'needs-install' | 'supported';

const SW_URL = '/sw.js';

function isIos(): boolean {
    return /iPad|iPhone|iPod/.test(navigator.userAgent);
}

function isStandalone(): boolean {
    return (
        window.matchMedia('(display-mode: standalone)').matches ||
        (navigator as Navigator & { standalone?: boolean }).standalone === true
    );
}

/**
 * En iPhone/iPad los avisos sólo existen con SAM agregado a la pantalla de
 * inicio; en el resto basta con Service Worker + Push API.
 */
export function pushSupport(): PushSupport {
    if (typeof window === 'undefined') {
        return 'unsupported';
    }

    if (isIos() && !isStandalone()) {
        return 'needs-install';
    }

    return 'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window
        ? 'supported'
        : 'unsupported';
}

export async function registerServiceWorker(): Promise<ServiceWorkerRegistration | null> {
    if (!('serviceWorker' in navigator)) {
        return null;
    }

    try {
        return await navigator.serviceWorker.register(SW_URL, { scope: '/' });
    } catch {
        return null;
    }
}

export async function currentSubscription(): Promise<PushSubscription | null> {
    const registration = await registerServiceWorker();

    return registration ? registration.pushManager.getSubscription() : null;
}

function urlBase64ToUint8Array(base64: string): Uint8Array<ArrayBuffer> {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4))
        .replace(/-/g, '+')
        .replace(/_/g, '/');
    const raw = window.atob(padded);
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));

    for (let i = 0; i < raw.length; i += 1) {
        bytes[i] = raw.charCodeAt(i);
    }

    return bytes;
}

async function saveSubscription(subscription: PushSubscription): Promise<boolean> {
    const json = subscription.toJSON();
    const response = await postJson(storeSubscription.url(), {
        endpoint: json.endpoint,
        keys: json.keys,
        content_encoding: PushManager.supportedContentEncodings?.includes('aes128gcm')
            ? 'aes128gcm'
            : 'aesgcm',
    });

    return response.ok;
}

/**
 * Pide permiso (sólo llamar desde un clic) y registra este dispositivo.
 */
export async function subscribeDevice(publicKey: string): Promise<boolean> {
    const permission = await Notification.requestPermission();

    if (permission !== 'granted') {
        return false;
    }

    const registration = await registerServiceWorker();

    if (!registration) {
        return false;
    }

    const subscription =
        (await registration.pushManager.getSubscription()) ??
        (await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(publicKey),
        }));

    return saveSubscription(subscription);
}

export async function unsubscribeDevice(): Promise<void> {
    const subscription = await currentSubscription();

    if (!subscription) {
        return;
    }

    await deleteJson(destroySubscription.url(), {
        endpoint: subscription.endpoint,
    });
    await subscription.unsubscribe();
}

/**
 * Al abrir la app con avisos ya activos, re-registra la suscripción: si el
 * navegador la rotó, o si ahora es otro usuario/team quien usa este
 * navegador, el servidor la reasigna (y el anterior deja de recibir aquí).
 */
export async function syncSubscription(): Promise<void> {
    if (pushSupport() !== 'supported' || Notification.permission !== 'granted') {
        return;
    }

    const subscription = await currentSubscription();

    if (subscription) {
        await saveSubscription(subscription);
    }
}
```

> Verificar el import real que generó Wayfinder (`ls resources/js/routes/push-subscriptions*`) y su API (`store.url()`). Si `PushManager.supportedContentEncodings` no tipa, usar `(PushManager as unknown as { supportedContentEncodings?: string[] })` — es el único cast permitido, con comentario.

- [ ] **Step 2: `hooks/use-web-push.ts`**

```ts
import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    currentSubscription,
    pushSupport,
    subscribeDevice,
    unsubscribeDevice,
} from '@/lib/web-push';

export type WebPushStatus =
    | 'loading'
    | 'unsupported'
    | 'needs-install'
    | 'unconfigured'
    | 'blocked'
    | 'off'
    | 'on';

async function resolveStatus(publicKey: string | null): Promise<WebPushStatus> {
    const support = pushSupport();

    if (support !== 'supported') {
        return support;
    }

    if (!publicKey) {
        return 'unconfigured';
    }

    if (Notification.permission === 'denied') {
        return 'blocked';
    }

    return (await currentSubscription()) ? 'on' : 'off';
}

export function useWebPush() {
    const publicKey = usePage().props.webPush?.publicKey ?? null;
    const [status, setStatus] = useState<WebPushStatus>('loading');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        let cancelled = false;

        void resolveStatus(publicKey).then((next) => {
            if (!cancelled) {
                setStatus(next);
            }
        });

        return () => {
            cancelled = true;
        };
    }, [publicKey]);

    async function enable(): Promise<void> {
        if (!publicKey) {
            return;
        }

        setBusy(true);
        const ok = await subscribeDevice(publicKey).catch(() => false);
        setStatus(ok ? 'on' : await resolveStatus(publicKey));
        setBusy(false);
    }

    async function disable(): Promise<void> {
        setBusy(true);
        await unsubscribeDevice().catch(() => undefined);
        setStatus(await resolveStatus(publicKey));
        setBusy(false);
    }

    return { status, busy, enable, disable };
}
```

- [ ] **Step 3: Tarjeta en "Mis avisos"**

`resources/js/components/sam/settings/device-push-card.tsx`:

```tsx
import { BellRing } from 'lucide-react';
import { FormCard } from '@/components/sam/field';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { WebPushStatus } from '@/hooks/use-web-push';
import { useWebPush } from '@/hooks/use-web-push';

const COPY: Record<WebPushStatus, string> = {
    loading: 'Revisando este dispositivo…',
    unsupported:
        'Este navegador no puede recibir avisos con SAM cerrado. Usa Chrome, Edge, Firefox o Safari actualizados.',
    'needs-install':
        'En iPhone, primero agrega SAM a tu pantalla de inicio: toca Compartir y luego "Agregar a inicio". Abre SAM desde ese ícono y vuelve aquí.',
    unconfigured: 'Los avisos al dispositivo todavía no están disponibles.',
    blocked:
        'Bloqueaste los avisos de SAM en este navegador. Actívalos en la configuración del sitio y vuelve a intentarlo.',
    off: 'Recibe las alertas urgentes y lo que te asignen aunque SAM esté cerrado. Suenan como cualquier notificación del teléfono; las llamadas de emergencia siguen llegando igual.',
    on: 'Este dispositivo recibe tus avisos de SAM aunque la app esté cerrada.',
};

export function DevicePushCard() {
    const { status, busy, enable, disable } = useWebPush();
    const actionable = status === 'off' || status === 'on';

    return (
        <FormCard className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-start gap-3">
                <BellRing size={18} className="mt-0.5 shrink-0 text-fg-3" />
                <p className="text-sm text-fg-2">{COPY[status]}</p>
            </div>
            {actionable && (
                <Button
                    type="button"
                    variant={status === 'on' ? 'outline' : 'default'}
                    disabled={busy}
                    onClick={() => void (status === 'on' ? disable() : enable())}
                    className="shrink-0"
                >
                    {busy && <Spinner />}
                    {status === 'on'
                        ? 'Desactivar en este dispositivo'
                        : 'Activar en este dispositivo'}
                </Button>
            )}
        </FormCard>
    );
}
```

En `pages/settings/notifications.tsx`, como primera sección dentro de `<SettingsPage>`:

```tsx
                <SettingsSection
                    title="Avisos en este dispositivo"
                    description="Se activa por teléfono o computadora; cada uno se configura por separado."
                >
                    <DevicePushCard />
                </SettingsSection>
```
(import `DevicePushCard`). Verificar que las clases `text-fg-2`/`text-fg-3` existen en `@theme` (`grep -n "fg-2\|fg-3" resources/css/app.css`); si no, usar las que usa `tenant-setup-banner.tsx`.

- [ ] **Step 4: Banner y sincronización en el layout**

`resources/js/components/push-opt-in-banner.tsx`:

```tsx
import { BellRing, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { useWebPush } from '@/hooks/use-web-push';
import { syncSubscription } from '@/lib/web-push';

const DISMISSED_KEY = 'sam.push-banner.dismissed';

function readDismissed(): boolean {
    try {
        return window.localStorage.getItem(DISMISSED_KEY) === '1';
    } catch {
        return false;
    }
}

function writeDismissed(): void {
    try {
        window.localStorage.setItem(DISMISSED_KEY, '1');
    } catch {
        // Sin almacenamiento: el banner volverá a salir; no es grave.
    }
}

/**
 * Invita a activar avisos en este dispositivo mientras el permiso no se ha
 * pedido. También re-sincroniza la suscripción al abrir la app (rotación del
 * navegador o cambio de usuario/team en el mismo navegador).
 */
export function PushOptInBanner() {
    const { status, busy, enable } = useWebPush();
    const [dismissed, setDismissed] = useState(readDismissed);

    useEffect(() => {
        void syncSubscription().catch(() => undefined);
    }, []);

    if (dismissed || status !== 'off' || Notification.permission !== 'default') {
        return null;
    }

    return (
        <div
            role="status"
            className="flex shrink-0 flex-col gap-2 border-b border-border bg-surface-2 px-4 py-2 text-sm text-fg-1 sm:flex-row sm:items-center sm:justify-between"
        >
            <span className="flex items-center gap-2">
                <BellRing size={16} className="shrink-0" />
                Recibe las alertas en este dispositivo aunque SAM esté cerrado.
            </span>
            <span className="flex items-center gap-2">
                <Button type="button" size="sm" disabled={busy} onClick={() => void enable()}>
                    Activar
                </Button>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    aria-label="Ahora no"
                    onClick={() => {
                        writeDismissed();
                        setDismissed(true);
                    }}
                >
                    <X size={16} />
                </Button>
            </span>
        </div>
    );
}
```

> `Notification.permission` en render: el hook ya devolvió `'unsupported'` cuando `Notification` no existe, así que la condición `status !== 'off'` corta antes. Verificar `bg-surface-2` en `@theme`; usar el token de superficie que exista.

En `ops-layout.tsx`, después de `<TenantSetupBanner />`:

```tsx
                <PushOptInBanner />
```
(import).

- [ ] **Step 5: Gates de frontend**

Run: `npm run types:check && npm run lint:check && npm run format:check && npm run build`
Expected: todo verde. Si `format:check` falla, `npx prettier --write` sobre los archivos tocados.

- [ ] **Step 6: Commit**

```bash
git add resources/js
git commit -m "feat: activar avisos de sam en este dispositivo desde mis avisos"
```

---

### Task 10: Verificación de punta a punta y gate

**Files:** ninguno nuevo (sólo arreglos que salgan).

- [ ] **Step 1: Gate completo**

Run: `vendor/bin/pint --dirty --format agent && php artisan test --compact && composer analyse && npm run types:check && npm run lint:check && npm run format:check`
Expected: todo verde. Arreglar la causa de cualquier rojo con un commit nuevo (sin baseline, sin ignore).

- [ ] **Step 2: Push real en local**

1. `php artisan sam:vapid-keys` → pegar en `.env` del entorno de preview junto a `VAPID_SUBJECT=mailto:soporte@example.com`; `php artisan config:clear`; `php artisan migrate`.
2. Abrir la app en el navegador del panel (localhost es contexto seguro), iniciar sesión con el usuario de seed, ir a "Mis avisos" → "Activar en este dispositivo" → aceptar permiso.
3. Confirmar fila: `php artisan tinker --execute="echo App\Domains\Notifications\Models\PushSubscription::withoutGlobalScopes()->count();"` → `1`.
4. Disparar un aviso real: asignar un incidente a ese usuario desde otro usuario (o `php artisan tinker` con `AssignIncident`), con Horizon/cola corriendo.
5. Confirmar que aparece la notificación del sistema y que tocarla abre el incidente. Revisar `notifications.push.sent` en el log.
6. Pedirle al usuario que lo pruebe en su teléfono (Android: Chrome; iPhone: agregar a inicio) contra un entorno HTTPS (ngrok, como la receta de Twilio).

- [ ] **Step 3: Revisión de fuga de tenant**

Despachar el agente `tenant-isolation-reviewer` sobre la rama antes del PR.

- [ ] **Step 4: PR**

Push de la rama y PR con resumen, pasos de despliegue (`sam:vapid-keys` → `.env`, `migrate`, `docker compose restart horizon scheduler`) y la lista de lo que queda fuera (botón "Tomar" en la notificación).
