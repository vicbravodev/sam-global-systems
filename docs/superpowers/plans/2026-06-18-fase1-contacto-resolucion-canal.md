# Fase 1 — Contactabilidad: campos de teléfono + resolución por canal — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar a `User` y `Driver` un teléfono, y hacer que las notificaciones elijan el destino correcto por canal (email → correo; sms/voz/whatsapp → teléfono), de modo que la escalera por voz/SMS deje de mandar emails a Twilio.

**Architecture:** Campos `phone` directos en `users` y `drivers` (additive). `NotificationRecipient` y `RecipientDescriptor` ganan `email` + `phone`; la resolución del destino por canal ocurre en `DispatchNotification` justo antes de renderizar/enviar, usando `NotificationRecipient::addressForChannel()`. Si un canal telefónico no tiene teléfono, se registra un delivery `Skipped` (nuevo estado) y se continúa — nunca se pasa un email a Twilio como número.

**Tech Stack:** Laravel 13 · PHP 8.5 · PHPUnit 12 (sqlite `:memory:`) · Pint. Sin dependencias nuevas. Migraciones additive-only.

**Referencia:** spec [docs/superpowers/specs/2026-06-18-contactabilidad-emergencias-design.md](../specs/2026-06-18-contactabilidad-emergencias-design.md), secciones 3.1 y 3.2.

---

## File Structure

**Crear:**
- `database/migrations/2026_06_18_120000_add_phone_to_users_table.php` — columnas `phone`, `phone_verified_at`.
- `database/migrations/2026_06_18_120010_add_phone_to_drivers_table.php` — columna `phone`.
- `database/migrations/2026_06_18_120020_add_email_phone_to_notification_recipients_table.php` — columnas `email`, `phone`.
- `tests/Feature/Domains/Access/UserPhoneFieldTest.php`
- `tests/Feature/Domains/Drivers/DriverPhoneFieldTest.php`
- `tests/Feature/Domains/Notifications/RecipientChannelAddressTest.php`
- `tests/Feature/Domains/Notifications/ChannelAwareDispatchTest.php`

**Modificar:**
- `app/Models/User.php` — `phone` fillable, cast `phone_verified_at`.
- `app/Domains/Drivers/Models/Driver.php` — `phone` fillable.
- `app/Domains/Notifications/Models/NotificationRecipient.php` — `email`/`phone` fillable + `addressForChannel()`.
- `app/Domains/Notifications/Data/RecipientDescriptor.php` — `email`/`phone`.
- `app/Domains/Notifications/Actions/ResolveRecipients.php` — poblar `email`/`phone`.
- `app/Domains/Notifications/Enums/DeliveryStatus.php` — caso `Skipped`.
- `app/Domains/Notifications/Actions/RenderNotificationContent.php` — parámetro `addressOverride`.
- `app/Domains/Notifications/Actions/DispatchNotification.php` — resolución por canal + registro de skip.

---

## Task 1: Campo `phone` en `users` y `drivers`

**Files:**
- Create: `database/migrations/2026_06_18_120000_add_phone_to_users_table.php`
- Create: `database/migrations/2026_06_18_120010_add_phone_to_drivers_table.php`
- Modify: `app/Models/User.php`
- Modify: `app/Domains/Drivers/Models/Driver.php`
- Test: `tests/Feature/Domains/Access/UserPhoneFieldTest.php`, `tests/Feature/Domains/Drivers/DriverPhoneFieldTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Domains/Access/UserPhoneFieldTest.php`:

```php
<?php

namespace Tests\Feature\Domains\Access;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPhoneFieldTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_persists_phone_and_casts_verified_at(): void
    {
        $user = User::factory()->create([
            'phone' => '+5215555550123',
            'phone_verified_at' => now(),
        ]);

        $fresh = $user->fresh();

        $this->assertSame('+5215555550123', $fresh->phone);
        $this->assertNotNull($fresh->phone_verified_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->phone_verified_at);
    }

    public function test_phone_is_nullable_by_default(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->fresh()->phone);
        $this->assertNull($user->fresh()->phone_verified_at);
    }
}
```

`tests/Feature/Domains/Drivers/DriverPhoneFieldTest.php`:

```php
<?php

namespace Tests\Feature\Domains\Drivers;

use App\Domains\Drivers\Models\Driver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverPhoneFieldTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_persists_phone(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $driver = Driver::factory()->create([
            'team_id' => $user->currentTeam->id,
            'phone' => '+5215555550199',
        ]);

        $this->assertSame('+5215555550199', $driver->fresh()->phone);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="UserPhoneFieldTest|DriverPhoneFieldTest"`
Expected: FAIL — `phone` column / attribute does not exist.

- [ ] **Step 3: Write the migrations**

`database/migrations/2026_06_18_120000_add_phone_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'phone_verified_at']);
        });
    }
};
```

`database/migrations/2026_06_18_120010_add_phone_to_drivers_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('employee_code');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
```

- [ ] **Step 4: Update the models**

In `app/Models/User.php`, change the `#[Fillable(...)]` attribute to include `phone`:

```php
#[Fillable(['name', 'email', 'phone', 'password', 'current_team_id', 'global_role'])]
```

And add the cast inside `casts()`:

```php
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
```

In `app/Domains/Drivers/Models/Driver.php`, add `'phone'` to `$fillable` (after `'employee_code'`):

```php
    protected $fillable = [
        'team_id',
        'external_primary_id',
        'first_name',
        'last_name',
        'full_name',
        'employee_code',
        'phone',
        'status',
        'metadata_json',
        'first_seen_at',
        'last_seen_at',
    ];
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter="UserPhoneFieldTest|DriverPhoneFieldTest"`
Expected: PASS (3 tests).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/User.php app/Domains/Drivers/Models/Driver.php database/migrations/2026_06_18_120000_add_phone_to_users_table.php database/migrations/2026_06_18_120010_add_phone_to_drivers_table.php tests/Feature/Domains/Access/UserPhoneFieldTest.php tests/Feature/Domains/Drivers/DriverPhoneFieldTest.php
git commit -m "feat(contact): add phone field to users and drivers"
```

---

## Task 2: `email`/`phone` en `NotificationRecipient` + `addressForChannel()`

**Files:**
- Create: `database/migrations/2026_06_18_120020_add_email_phone_to_notification_recipients_table.php`
- Modify: `app/Domains/Notifications/Models/NotificationRecipient.php`
- Modify: `app/Domains/Notifications/Data/RecipientDescriptor.php`
- Test: `tests/Feature/Domains/Notifications/RecipientChannelAddressTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Domains/Notifications/RecipientChannelAddressTest.php`:

```php
<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipientChannelAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_address_for_channel_picks_phone_for_telephony_and_email_for_mail(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $recipient = NotificationRecipient::factory()->create([
            'team_id' => $user->currentTeam->id,
            'address' => 'ops@example.com',
            'email' => 'ops@example.com',
            'phone' => '+5215555550100',
        ]);

        $this->assertSame('ops@example.com', $recipient->addressForChannel(ChannelType::Email));
        $this->assertSame('+5215555550100', $recipient->addressForChannel(ChannelType::Sms));
        $this->assertSame('+5215555550100', $recipient->addressForChannel(ChannelType::Voice));
        $this->assertSame('+5215555550100', $recipient->addressForChannel(ChannelType::Whatsapp));
    }

    public function test_address_for_telephony_is_null_when_phone_missing(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $recipient = NotificationRecipient::factory()->create([
            'team_id' => $user->currentTeam->id,
            'address' => 'ops@example.com',
            'email' => 'ops@example.com',
            'phone' => null,
        ]);

        $this->assertNull($recipient->addressForChannel(ChannelType::Sms));
        // Mail still resolves via email/address fallback.
        $this->assertSame('ops@example.com', $recipient->addressForChannel(ChannelType::Email));
    }

    public function test_non_telephony_channels_fall_back_to_address(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $recipient = NotificationRecipient::factory()->create([
            'team_id' => $user->currentTeam->id,
            'address' => 'someone@example.com',
            'email' => null,
            'phone' => null,
        ]);

        $this->assertSame('someone@example.com', $recipient->addressForChannel(ChannelType::Web));
        $this->assertSame('someone@example.com', $recipient->addressForChannel(ChannelType::Push));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=RecipientChannelAddressTest`
Expected: FAIL — `email`/`phone` not fillable / `addressForChannel` not defined.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_06_18_120020_add_email_phone_to_notification_recipients_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_recipients', function (Blueprint $table) {
            // `address` stays as the legacy default address; `email`/`phone`
            // let DispatchNotification resolve the right destination per channel.
            $table->string('email')->nullable()->after('address');
            $table->string('phone')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('notification_recipients', function (Blueprint $table) {
            $table->dropColumn(['email', 'phone']);
        });
    }
};
```

- [ ] **Step 4: Update the model**

In `app/Domains/Notifications/Models/NotificationRecipient.php`, add `'email'` and `'phone'` to `$fillable` (after `'address'`), and add the resolution method. Add the import `use App\Domains\Notifications\Enums\ChannelType;` at the top.

```php
    protected $fillable = [
        'notification_id',
        'team_id',
        'recipient_type',
        'recipient_reference_id',
        'name',
        'address',
        'email',
        'phone',
        'channel_preference',
        'role',
        'metadata_json',
    ];

    /**
     * The destination to use for a given channel: telephony channels need a
     * phone, mail needs an email (falling back to the legacy address), and
     * everything else keeps using the legacy address.
     */
    public function addressForChannel(ChannelType $channelType): ?string
    {
        return match ($channelType) {
            ChannelType::Sms, ChannelType::Voice, ChannelType::Whatsapp => $this->phone ?: null,
            ChannelType::Email => $this->email ?: $this->address ?: null,
            default => $this->address ?: null,
        };
    }
```

- [ ] **Step 5: Update the DTO**

In `app/Domains/Notifications/Data/RecipientDescriptor.php`, add `email` and `phone` constructor properties (after `address`):

```php
    public function __construct(
        public readonly RecipientType $recipientType,
        public readonly string $address,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $name = null,
        public readonly ?string $referenceId = null,
        public readonly ?string $channelPreference = null,
        public readonly ?string $role = null,
        public readonly ?array $metadata = null,
    ) {}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact --filter=RecipientChannelAddressTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Domains/Notifications/Models/NotificationRecipient.php app/Domains/Notifications/Data/RecipientDescriptor.php database/migrations/2026_06_18_120020_add_email_phone_to_notification_recipients_table.php tests/Feature/Domains/Notifications/RecipientChannelAddressTest.php
git commit -m "feat(notifications): per-channel address resolution on recipient"
```

---

## Task 3: `ResolveRecipients` puebla `email`/`phone`

**Files:**
- Modify: `app/Domains/Notifications/Actions/ResolveRecipients.php`
- Test: `tests/Feature/Domains/Notifications/RecipientChannelAddressTest.php` (añadir métodos)

- [ ] **Step 1: Add failing tests for resolution**

Append these methods to `tests/Feature/Domains/Notifications/RecipientChannelAddressTest.php` (add imports `use App\Domains\Notifications\Actions\ResolveRecipients;`, `use App\Domains\Notifications\Models\Notification;`, `use App\Domains\Notifications\Enums\NotificationPriority;`, `use App\Domains\Notifications\Enums\NotificationStatus;`, `use App\Domains\Notifications\Enums\RecipientType;`):

```php
    public function test_team_fanout_includes_user_phone(): void
    {
        $user = User::factory()->create(['phone' => '+5215555550144']);
        $team = $user->currentTeam;
        $this->actingAs($user);

        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'manual.test',
            'priority' => NotificationPriority::Normal,
            'status' => NotificationStatus::Queued,
            'payload_json' => [],
        ]);

        $descriptors = app(ResolveRecipients::class)->execute($notification);

        $this->assertCount(1, $descriptors);
        $this->assertSame($user->email, $descriptors[0]->email);
        $this->assertSame('+5215555550144', $descriptors[0]->phone);
    }

    public function test_explicit_contact_classifies_phone_vs_email(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'notification_type' => 'manual.test',
            'priority' => NotificationPriority::Normal,
            'status' => NotificationStatus::Queued,
            'payload_json' => [
                'recipients' => [
                    ['recipient_type' => RecipientType::ExternalContact->value, 'address' => '+5215555550155'],
                    ['recipient_type' => RecipientType::ExternalContact->value, 'address' => 'ops@example.com'],
                ],
            ],
        ]);

        $descriptors = app(ResolveRecipients::class)->execute($notification);

        $this->assertSame('+5215555550155', $descriptors[0]->phone);
        $this->assertNull($descriptors[0]->email);
        $this->assertSame('ops@example.com', $descriptors[1]->email);
        $this->assertNull($descriptors[1]->phone);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=RecipientChannelAddressTest`
Expected: FAIL — `phone`/`email` on descriptors are null (not populated yet).

- [ ] **Step 3: Update `ResolveRecipients`**

In `app/Domains/Notifications/Actions/ResolveRecipients.php`, replace `buildExplicit`'s descriptor construction and `buildFromTeamMembers` to populate `email`/`phone`. Add a private E.164 helper.

In `buildExplicit`, after resolving `$address`, compute:

```php
            $email = isset($entry['email']) && is_string($entry['email'])
                ? $entry['email']
                : (str_contains($address, '@') ? $address : null);

            $phone = isset($entry['phone']) && is_string($entry['phone'])
                ? $entry['phone']
                : ($this->looksLikePhone($address) ? $address : null);

            $descriptors[] = new RecipientDescriptor(
                recipientType: $type,
                address: $address,
                email: $email,
                phone: $phone,
                name: isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : null,
                referenceId: isset($entry['recipient_reference_id']) ? (string) $entry['recipient_reference_id'] : null,
                channelPreference: isset($entry['channel_preference']) && is_string($entry['channel_preference']) ? $entry['channel_preference'] : null,
                role: isset($entry['role']) && is_string($entry['role']) ? $entry['role'] : null,
                metadata: isset($entry['metadata']) && is_array($entry['metadata']) ? $entry['metadata'] : null,
            );
```

In `buildFromTeamMembers`, change the descriptor construction to:

```php
            $descriptors[] = new RecipientDescriptor(
                recipientType: RecipientType::User,
                address: $user->email,
                email: $user->email,
                phone: $user->phone,
                name: $user->name,
                referenceId: (string) $user->id,
                role: $membership->getRawOriginal('role'),
            );
```

Add the helper method at the end of the class:

```php
    private function looksLikePhone(string $value): bool
    {
        return preg_match('/^\+[0-9]{8,15}$/', trim($value)) === 1;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=RecipientChannelAddressTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Domains/Notifications/Actions/ResolveRecipients.php tests/Feature/Domains/Notifications/RecipientChannelAddressTest.php
git commit -m "feat(notifications): resolve user/external email and phone for recipients"
```

---

## Task 4: Resolución por canal en el envío + delivery `Skipped`

**Files:**
- Modify: `app/Domains/Notifications/Enums/DeliveryStatus.php`
- Modify: `app/Domains/Notifications/Actions/RenderNotificationContent.php`
- Modify: `app/Domains/Notifications/Actions/DispatchNotification.php`
- Test: `tests/Feature/Domains/Notifications/ChannelAwareDispatchTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Domains/Notifications/ChannelAwareDispatchTest.php`:

```php
<?php

namespace Tests\Feature\Domains\Notifications;

use App\Domains\Notifications\Actions\DispatchNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelAwareDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NotificationMeterSeeder::class);
    }

    public function test_sms_to_recipient_without_phone_is_skipped_not_emailed(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        NotificationChannel::factory()->sms()->create([
            'team_id' => $team->id,
            'is_active' => true,
            'channel_type' => ChannelType::Sms,
        ]);

        // Critical → force-selects every usable channel; external email-only contact has no phone.
        $notification = Notification::factory()->critical()->create([
            'team_id' => $team->id,
            'notification_type' => 'incident.critical',
            'status' => NotificationStatus::Queued,
            'payload_json' => [
                'recipients' => [
                    ['recipient_type' => RecipientType::ExternalContact->value, 'address' => 'ops@example.com'],
                ],
            ],
        ]);

        app(DispatchNotification::class)->execute($notification);

        $delivery = NotificationDelivery::withoutGlobalScopes()
            ->where('notification_id', $notification->id)
            ->first();

        $this->assertNotNull($delivery);
        $this->assertSame(DeliveryStatus::Skipped, $delivery->status);
        $this->assertStringContainsString('phone', (string) $delivery->error_message);
    }

    public function test_sms_to_recipient_with_phone_targets_the_phone(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        NotificationChannel::factory()->sms()->create([
            'team_id' => $team->id,
            'is_active' => true,
            'channel_type' => ChannelType::Sms,
        ]);

        $notification = Notification::factory()->critical()->create([
            'team_id' => $team->id,
            'notification_type' => 'incident.critical',
            'status' => NotificationStatus::Queued,
            'payload_json' => [
                'recipients' => [
                    ['recipient_type' => RecipientType::ExternalContact->value, 'address' => '+5215555550188'],
                ],
            ],
        ]);

        app(DispatchNotification::class)->execute($notification);

        $delivery = NotificationDelivery::withoutGlobalScopes()
            ->where('notification_id', $notification->id)
            ->first();

        $this->assertNotNull($delivery);
        $this->assertNotSame(DeliveryStatus::Skipped, $delivery->status);
        // The rendered payload targeted the phone, not an email.
        $this->assertSame('+5215555550188', $delivery->payload_json['address'] ?? null);
    }
}
```

> Note: this test asserts `payload_json['address']`; Step 4 adds the resolved `address` to the delivery payload so the target is observable without faking Twilio.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ChannelAwareDispatchTest`
Expected: FAIL — `DeliveryStatus::Skipped` undefined / no per-channel resolution.

- [ ] **Step 3: Add the `Skipped` status**

In `app/Domains/Notifications/Enums/DeliveryStatus.php`, add the case (keep it out of `isTerminal()` or include it — it is terminal for our purposes):

```php
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sending = 'sending';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Bounced = 'bounced';
    case Retrying = 'retrying';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Failed, self::Bounced, self::Cancelled, self::Skipped], true);
    }
}
```

- [ ] **Step 4: Add `addressOverride` to `RenderNotificationContent`**

In `app/Domains/Notifications/Actions/RenderNotificationContent.php`, add the optional parameter and use it for the rendered address:

```php
    public function execute(
        Notification $notification,
        NotificationRecipient $recipient,
        ChannelType $channelType,
        ?NotificationTemplate $template = null,
        ?string $addressOverride = null,
    ): RenderedNotification {
```

And change the `address:` argument of the returned `RenderedNotification`:

```php
        return new RenderedNotification(
            channelType: $channelType,
            address: $addressOverride ?? $recipient->address,
            subject: $subject,
            body: $body,
            variables: $variables,
            recipientName: $recipient->name,
        );
```

- [ ] **Step 5: Resolve per-channel in `DispatchNotification` and record skips**

In `app/Domains/Notifications/Actions/DispatchNotification.php`:

(a) When creating each `NotificationRecipient` (the `foreach ($descriptors ...)` loop), add `email`/`phone`:

```php
            $recipients[] = NotificationRecipient::query()->create([
                'notification_id' => $notification->id,
                'team_id' => $notification->team_id,
                'recipient_type' => $descriptor->recipientType,
                'recipient_reference_id' => $descriptor->referenceId,
                'name' => $descriptor->name,
                'address' => $descriptor->address,
                'email' => $descriptor->email,
                'phone' => $descriptor->phone,
                'channel_preference' => $descriptor->channelPreference,
                'role' => $descriptor->role,
                'metadata_json' => $descriptor->metadata,
            ]);
```

(b) Replace the inner channel loop body so it resolves the target address first, skips when absent, and renders with the override. Replace the existing `foreach ($channels as $channel) { ... }` block with:

```php
            foreach ($channels as $channel) {
                $targetAddress = $recipient->addressForChannel($channel->channel_type);

                if ($targetAddress === null || $targetAddress === '') {
                    $this->recordSkippedDelivery(
                        $notification,
                        $recipient,
                        $channel,
                        "no {$channel->channel_type->value} address (missing phone/email) for recipient",
                    );

                    continue;
                }

                $totalAttempts++;

                $delivery = $this->createDeliveryOrSkip($notification, $recipient, $channel);

                if ($delivery === null) {
                    continue;
                }

                $rendered = $this->render->execute($notification, $recipient, $channel->channel_type, null, $targetAddress);
                $rendered = $this->appendReplyInstructions($notification, $recipient, $rendered);

                $driver = $this->drivers->driverFor($channel->channel_type);

                $delivery->update([
                    'status' => DeliveryStatus::Sending,
                    'sent_at' => now(),
                    'payload_json' => [
                        'address' => $rendered->address,
                        'subject' => $rendered->subject,
                        'body' => $rendered->body,
                    ],
                ]);

                $result = $driver->send($rendered, $channel);

                $this->recordAttempt->execute($delivery, $result);

                $delivery->refresh();

                $this->recordUsage->execute(
                    teamId: $notification->team_id,
                    meterCode: 'outbound_notifications',
                    quantity: 1,
                    eventKey: "notif_delivery_{$delivery->id}",
                );

                if ($result->success) {
                    $deliveredCount++;
                    $this->afterSuccessfulDelivery($notification, $recipient, $delivery, $channel->channel_type);
                } else {
                    $failedCount++;
                    NotificationFailed::dispatch(
                        $notification->team_id,
                        $notification->id,
                        $delivery->id,
                        $channel->channel_type->value,
                        $result->errorMessage ?? 'Unknown error',
                    );
                }
            }
```

(c) Add the `recordSkippedDelivery` helper next to `createDeliveryOrSkip`:

```php
    private function recordSkippedDelivery(
        Notification $notification,
        NotificationRecipient $recipient,
        NotificationChannel $channel,
        string $reason,
    ): void {
        try {
            DB::transaction(function () use ($notification, $recipient, $channel, $reason) {
                $existing = NotificationDelivery::withoutGlobalScopes()
                    ->where('notification_id', $notification->id)
                    ->where('recipient_id', $recipient->id)
                    ->where('channel_id', $channel->id)
                    ->first();

                if ($existing) {
                    return;
                }

                NotificationDelivery::query()->create([
                    'notification_id' => $notification->id,
                    'recipient_id' => $recipient->id,
                    'channel_id' => $channel->id,
                    'team_id' => $notification->team_id,
                    'status' => DeliveryStatus::Skipped,
                    'attempt_number' => 0,
                    'error_message' => $reason,
                ]);
            });
        } catch (\Throwable) {
            // best-effort audit row; never break the dispatch loop
        }
    }
```

- [ ] **Step 6: Run the new test and the existing dispatch suite**

Run: `php artisan test --compact --filter="ChannelAwareDispatchTest|DispatchNotificationTest"`
Expected: PASS. `ChannelAwareDispatchTest` (2) green; `DispatchNotificationTest` (5) still green — the critical-multichannel test still sees ≥2 delivery rows (email delivered + sms skipped).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Domains/Notifications/Enums/DeliveryStatus.php app/Domains/Notifications/Actions/RenderNotificationContent.php app/Domains/Notifications/Actions/DispatchNotification.php tests/Feature/Domains/Notifications/ChannelAwareDispatchTest.php
git commit -m "feat(notifications): per-channel destination resolution with skipped deliveries"
```

---

## Task 5: Regresión completa del dominio

**Files:** none (verification only)

- [ ] **Step 1: Run the Notifications + Drivers + Access suites**

Run: `php artisan test --compact tests/Feature/Domains/Notifications tests/Feature/Domains/Drivers`
Expected: all green. If `test_critical_notification_uses_multiple_channels` fails on a status assertion, it does not assert status — only the row count (≥2), which holds.

- [ ] **Step 2: Run the full suite once**

Run: `php artisan test --compact`
Expected: green (no regressions from the new column/enum/resolution).

- [ ] **Step 3: Final format check**

Run: `vendor/bin/pint --dirty --format agent`
Expected: no pending changes (already formatted per-task).

---

## Self-Review

**Spec coverage (sección 3.1 + 3.2 del spec):**
- `users.phone` + `phone_verified_at` → Task 1 ✓
- `drivers.phone` → Task 1 ✓
- `RecipientDescriptor` con email+phone → Task 2 ✓ (el método de resolución vive en `NotificationRecipient::addressForChannel`, donde el loop de envío realmente lo usa — ajuste consciente respecto al spec, que lo situaba en el descriptor)
- Resolución por canal en `DispatchNotification`, skip + registro cuando falta el dato → Task 4 ✓
- `ResolveRecipients` incluye phone (fan-out) y clasifica externos phone/email → Task 3 ✓
- `DriverContact` intacto, `User`/`Team` extendidos no reemplazados, additive-only → respetado ✓
- Facturación/OTP/escalación/protocolo conductor → **fuera de esta fase** (Fases 2–4) ✓

**Placeholder scan:** sin TBD/TODO; cada paso lleva código o comando concreto. ✓

**Type consistency:** `addressForChannel(ChannelType): ?string` se define en Task 2 y se consume en Task 4 con la misma firma; `RecipientDescriptor` gana `email`/`phone` en Task 2 y se usan en Task 3 (construcción) y Task 4 (persistencia). `RenderNotificationContent::execute` gana `?string $addressOverride = null` como **último** parámetro (tras `$template`), y Task 4 lo invoca como `execute($notification, $recipient, $channelType, null, $targetAddress)` — posiciones consistentes. ✓

**Riesgo verificado:** `DispatchNotification::createDeliveryOrSkip` e `recordSkippedDelivery` comparten la clave única `(notification_id, recipient_id, channel_id)`, así que un re-dispatch no duplica filas. `payload_json['address']` se añade al delivery para poder afirmar el destino sin faquear Twilio.
