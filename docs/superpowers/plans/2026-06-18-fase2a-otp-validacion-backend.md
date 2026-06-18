# Fase 2a — Backend: validación E.164 + OTP de teléfono (sin UI) — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Validar teléfonos en formato E.164 en el perfil de user y en los contactos `mobile_phone` del driver; persistir y normalizar `Driver.phone` desde el sync de integración; y construir el backend de verificación de teléfono por OTP (SMS) a nivel de perfil de usuario.

**Architecture:** Una utilidad `App\Support\PhoneNumber` centraliza el contrato E.164 (un único regex `/^\+[0-9]{8,15}$/`, ya usado en el repo) y la normalización conservadora (limpieza ligera → E.164 o null). Una regla `App\Rules\ValidE164Phone` la consume. El OTP vive en `Settings` (rutas de usuario, NO tenant-scoped) reutilizando el canal SMS del tenant y la caché (Valkey) con TTL; rate-limiter `otp`; meter `otp_sms_sent`; auditoría de resultados (no del código).

**Tech Stack:** Laravel 13 · PHP 8.5 · PHPUnit 12 (sqlite `:memory:`) · Pint. Sin dependencias nuevas (sin libphonenumber).

**Decisiones (del brainstorming + workflow):** sin canal SMS → fallo con mensaje claro (sin email OTP); verificación opcional (no bloquea acciones); auditar resultados de OTP, nunca el código; el sync de integración SÍ persiste/normaliza `Driver.phone`. Spec: [docs/superpowers/specs/2026-06-18-contactabilidad-emergencias-design.md](../specs/2026-06-18-contactabilidad-emergencias-design.md) §3.1/§3.5.

**Reglas de commit:** solo identidad del usuario (sin `Co-Authored-By`/banners), no push, commits por tarea. Entorno del worktree ya bootstrapeado (vendor real, .env, public/build). `vendor/bin/pint --dirty --format agent` tras cada cambio PHP.

---

## File Structure

**Crear:**
- `app/Support/PhoneNumber.php` — contrato E.164: `isE164()`, `normalize()`.
- `app/Rules/ValidE164Phone.php` — `ValidationRule` que usa `PhoneNumber::isE164`.
- `app/Support/OtpCacheKeys.php` — clave de caché, TTL, cap de intentos.
- `app/Domains/Access/Actions/SendPhoneOtp.php` — genera, cachea, envía SMS, factura, audita.
- `app/Domains/Access/Actions/VerifyPhoneOtp.php` — valida código, sella `phone_verified_at`, audita.
- `app/Domains/Access/Data/OtpResult.php` — DTO de resultado (`ok`, `reason`).
- `app/Http/Controllers/Settings/PhoneVerificationController.php` — `send()` / `verify()`.
- `database/seeders/OtpMeterSeeder.php` — meter `otp_sms_sent`.
- Tests: `tests/Unit/Support/PhoneNumberTest.php`, `tests/Unit/Rules/ValidE164PhoneTest.php`, `tests/Feature/Settings/PhoneVerificationTest.php`.

**Modificar:**
- `app/Concerns/ProfileValidationRules.php` — `phoneRules()` + en `profileRules()`.
- `app/Http/Controllers/Settings/ProfileController.php` — prop `phoneVerified` + nullify `phone_verified_at`.
- `app/Http/Requests/Drivers/UpdateDriverContactsRequest.php` — E.164 condicional para `mobile_phone`.
- `app/Domains/Drivers/Actions/SyncDriverFromIntegration.php` — mapear `phone` normalizado (create+update).
- `app/Providers/FortifyServiceProvider.php` — rate-limiter `otp`.
- `routes/settings.php` — rutas `phone-verification.send|verify`.
- `database/seeders/DatabaseSeeder.php` — registrar `OtpMeterSeeder`.
- Tests existentes: `tests/Feature/Settings/ProfileUpdateTest.php`, `tests/Feature/Domains/Drivers/UpdateDriverContactsTest.php` (o crear), `tests/Feature/Domains/Drivers/SyncDriverFromIntegrationTest.php`.

---

## Task 1: `PhoneNumber` + `ValidE164Phone` (contrato E.164 centralizado)

**Files:**
- Create: `app/Support/PhoneNumber.php`, `app/Rules/ValidE164Phone.php`
- Test: `tests/Unit/Support/PhoneNumberTest.php`, `tests/Unit/Rules/ValidE164PhoneTest.php`

- [ ] **Step 1: Write the failing unit tests**

`tests/Unit/Support/PhoneNumberTest.php`:

```php
<?php

namespace Tests\Unit\Support;

use App\Support\PhoneNumber;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_is_e164_accepts_valid_and_rejects_invalid(): void
    {
        $this->assertTrue(PhoneNumber::isE164('+5215555550123'));
        $this->assertTrue(PhoneNumber::isE164('+14155551234'));
        $this->assertFalse(PhoneNumber::isE164(''));
        $this->assertFalse(PhoneNumber::isE164('4155551234'));     // no +
        $this->assertFalse(PhoneNumber::isE164('+12 34'));          // space
        $this->assertFalse(PhoneNumber::isE164('+1234567'));        // too short (<8)
        $this->assertFalse(PhoneNumber::isE164('+1234567890123456')); // >15
    }

    public function test_normalize_strips_separators_and_returns_e164_or_null(): void
    {
        $this->assertSame('+14155551234', PhoneNumber::normalize('+1 (415) 555-1234'));
        $this->assertSame('+5215555550123', PhoneNumber::normalize(' +52 1 5555 550123 '));
        $this->assertNull(PhoneNumber::normalize(null));
        $this->assertNull(PhoneNumber::normalize(''));
        $this->assertNull(PhoneNumber::normalize('415-555-1234')); // no country code → cannot assume
        $this->assertNull(PhoneNumber::normalize('not a phone'));
    }
}
```

`tests/Unit/Rules/ValidE164PhoneTest.php`:

```php
<?php

namespace Tests\Unit\Rules;

use App\Rules\ValidE164Phone;
use PHPUnit\Framework\TestCase;

class ValidE164PhoneTest extends TestCase
{
    private function fails(mixed $value): bool
    {
        $failed = false;
        (new ValidE164Phone)->validate('phone', $value, function () use (&$failed) {
            $failed = true;
        });

        return $failed;
    }

    public function test_accepts_valid_e164(): void
    {
        $this->assertFalse($this->fails('+5215555550123'));
    }

    public function test_rejects_malformed(): void
    {
        $this->assertTrue($this->fails(''));
        $this->assertTrue($this->fails('4155551234'));
        $this->assertTrue($this->fails('+12 34'));
        $this->assertTrue($this->fails('+1234567'));
        $this->assertTrue($this->fails('+1234567890123456'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="PhoneNumberTest|ValidE164PhoneTest"`
Expected: FAIL — classes not found.

- [ ] **Step 3: Create `PhoneNumber`**

`app/Support/PhoneNumber.php`:

```php
<?php

namespace App\Support;

class PhoneNumber
{
    /**
     * The canonical E.164 contract used across the app (profile phone,
     * driver mobile contacts, SMS/voice recipients): a leading '+' followed
     * by 8 to 15 digits.
     */
    public const E164_PATTERN = '/^\+[0-9]{8,15}$/';

    public static function isE164(string $value): bool
    {
        return preg_match(self::E164_PATTERN, trim($value)) === 1;
    }

    /**
     * Conservative normalization: strip common separators, then accept only
     * if the result is already E.164. We never infer a country code (no
     * libphonenumber), so a local number without '+' returns null.
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $cleaned = preg_replace('/[\s\-().]/', '', trim($raw)) ?? '';

        return self::isE164($cleaned) ? $cleaned : null;
    }
}
```

- [ ] **Step 4: Create `ValidE164Phone`**

`app/Rules/ValidE164Phone.php` (mirror `app/Rules/TeamName.php` structure):

```php
<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidE164Phone implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! PhoneNumber::isE164($value)) {
            $fail('El teléfono debe estar en formato internacional E.164, por ejemplo +5215555550123.');
        }
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter="PhoneNumberTest|ValidE164PhoneTest"`
Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/PhoneNumber.php app/Rules/ValidE164Phone.php tests/Unit/Support/PhoneNumberTest.php tests/Unit/Rules/ValidE164PhoneTest.php
git commit -m "feat(contact): centralize E.164 phone contract and validation rule"
```

---

## Task 2: Validación de teléfono en el perfil de user

**Files:**
- Modify: `app/Concerns/ProfileValidationRules.php`, `app/Http/Controllers/Settings/ProfileController.php`
- Test: `tests/Feature/Settings/ProfileUpdateTest.php` (extend)

- [ ] **Step 1: Add failing tests**

Append to `tests/Feature/Settings/ProfileUpdateTest.php` (add imports if missing: none beyond existing). Use the existing test's patterns (it already PATCHes `route('profile.update')` as the acting user):

```php
    public function test_valid_e164_phone_is_accepted(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => '+5215555550123',
        ])->assertSessionHasNoErrors();

        $this->assertSame('+5215555550123', $user->fresh()->phone);
    }

    public function test_malformed_phone_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => '4155551234',
        ])->assertSessionHasErrors('phone');
    }

    public function test_changing_phone_nullifies_phone_verified_at(): void
    {
        $user = User::factory()->create([
            'phone' => '+5215555550123',
            'phone_verified_at' => now(),
        ]);
        $this->actingAs($user);

        $this->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => '+5215555550999',
        ])->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->phone_verified_at);
    }
```

> If `ProfileUpdateTest` does not yet import `App\Models\User`, add `use App\Models\User;`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=ProfileUpdateTest`
Expected: the 3 new tests fail (phone not validated/persisted; verified_at not nullified).

- [ ] **Step 3: Add `phoneRules()` to the trait**

In `app/Concerns/ProfileValidationRules.php`, add `use App\Rules\ValidE164Phone;` at the top, add `'phone' => $this->phoneRules()` to the array returned by `profileRules()`, and add the method:

```php
    /**
     * Get the validation rules used to validate a user's phone (E.164).
     *
     * @return array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>
     */
    protected function phoneRules(): array
    {
        return ['nullable', 'string', new ValidE164Phone];
    }
```

Resulting `profileRules()`:

```php
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
            'phone' => $this->phoneRules(),
        ];
    }
```

(`ProfileUpdateRequest` already delegates to `profileRules()`, so no request change is needed.)

- [ ] **Step 4: Nullify `phone_verified_at` on change + expose `phoneVerified`**

In `app/Http/Controllers/Settings/ProfileController.php`:

`update()` — add the phone-dirty nullify right after the email block (mirrors lines 35-37):

```php
        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        if ($request->user()->isDirty('phone')) {
            $request->user()->phone_verified_at = null;
        }
```

`edit()` — add the `phoneVerified` prop:

```php
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'phoneVerified' => $request->user()->phone_verified_at !== null,
            'status' => $request->session()->get('status'),
        ]);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=ProfileUpdateTest`
Expected: PASS (existing + 3 new).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Concerns/ProfileValidationRules.php app/Http/Controllers/Settings/ProfileController.php tests/Feature/Settings/ProfileUpdateTest.php
git commit -m "feat(profile): validate user phone (E.164) and reset verification on change"
```

---

## Task 3: Validación E.164 en contactos `mobile_phone` del driver

**Files:**
- Modify: `app/Http/Requests/Drivers/UpdateDriverContactsRequest.php`
- Test: `tests/Feature/Domains/Drivers/UpdateDriverContactsTest.php` (extend, or create mirroring `DriverPolicyTest`)

- [ ] **Step 1: Add failing tests**

First check whether `tests/Feature/Domains/Drivers/UpdateDriverContactsTest.php` exists. If it does, append; otherwise create it mirroring `tests/Feature/Domains/Drivers/DriverPolicyTest.php` (uses `actingAs`, a role helper, `AccessSeeder`, and `putJson` to `/api/{team}/drivers/{driver}/contacts`). The driver contacts endpoint route name/path: confirm via `php artisan route:list --path=drivers | grep contacts` and use that exact URL.

Tests to add (adapt the auth/role setup to match `DriverPolicyTest`):

```php
    public function test_mobile_phone_contact_must_be_e164(): void
    {
        // ... arrange acting user with permission + a driver in their team (copy DriverPolicyTest setup) ...
        $response = $this->putJson($contactsUrl, [
            'contacts' => [
                ['contact_type' => 'mobile_phone', 'value' => '415-555-1234'],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('contacts.0.value');
    }

    public function test_valid_e164_mobile_phone_is_accepted(): void
    {
        // ... same arrange ...
        $response = $this->putJson($contactsUrl, [
            'contacts' => [
                ['contact_type' => 'mobile_phone', 'value' => '+5215555550123'],
            ],
        ]);

        $response->assertSuccessful();
    }

    public function test_non_phone_contact_types_are_not_e164_constrained(): void
    {
        // ... same arrange ...
        $response = $this->putJson($contactsUrl, [
            'contacts' => [
                ['contact_type' => 'email', 'value' => 'driver@example.com'],
            ],
        ]);

        $response->assertSuccessful();
    }
```

> Use the EXACT acting/role/driver-creation setup from `DriverPolicyTest` so authz passes; the assertions above are the new behavior. Replace `$contactsUrl` with the real route.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=UpdateDriverContacts`
Expected: `test_mobile_phone_contact_must_be_e164` fails (currently any string ≤255 passes).

- [ ] **Step 3: Add the conditional rule**

In `app/Http/Requests/Drivers/UpdateDriverContactsRequest.php`, add imports `use App\Rules\ValidE164Phone;` and `use Illuminate\Validation\Rule;` (Rule already imported), and replace the `contacts.*.value` rule with a per-item conditional using `Rule::forEach`:

```php
    public function rules(): array
    {
        return [
            'contacts' => ['required', 'array', 'min:1'],
            'contacts.*.contact_type' => ['required', 'string', Rule::enum(ContactType::class)],
            'contacts.*.label' => ['nullable', 'string', 'max:255'],
            'contacts.*.value' => ['required', 'string', 'max:255', Rule::forEach(function (mixed $value, string $attribute, array $data) {
                // $attribute is like "contacts.0.value"; derive the sibling contact_type.
                $index = explode('.', $attribute)[1] ?? null;
                $type = $index !== null ? ($data['contacts'][$index]['contact_type'] ?? null) : null;

                return $type === ContactType::MobilePhone->value ? [new ValidE164Phone] : [];
            })],
            'contacts.*.is_primary' => ['sometimes', 'boolean'],
            'contacts.*.is_emergency' => ['sometimes', 'boolean'],
        ];
    }
```

> Note: `Rule::forEach`'s callback receives `($value, $attribute, $data)` where `$data` is the full input. If `Rule::forEach` proves awkward for the nested array shape, fall back to a top-level `withValidator()` closure that loops `$this->input('contacts', [])` and calls `$validator->errors()->add("contacts.$i.value", ...)` when a `mobile_phone` value fails `PhoneNumber::isE164()`. Either approach is acceptable; pick the one that the test proves works.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=UpdateDriverContacts`
Expected: PASS (mobile_phone rejected when malformed, accepted when E.164, other types unaffected).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/Drivers/UpdateDriverContactsRequest.php tests/Feature/Domains/Drivers/UpdateDriverContactsTest.php
git commit -m "feat(drivers): require E.164 for mobile_phone driver contacts"
```

---

## Task 4: Persistir y normalizar `Driver.phone` en el sync

**Files:**
- Modify: `app/Domains/Drivers/Actions/SyncDriverFromIntegration.php`
- Test: `tests/Feature/Domains/Drivers/SyncDriverFromIntegrationTest.php` (extend)

Context: `SamsaraAdapter::mapDriver()` already puts `phone` into the payload (`'phone' => Arr::get($driver, 'phone')`), but `SyncDriverFromIntegration` drops it. We persist it normalized.

- [ ] **Step 1: Add failing tests**

Append to `tests/Feature/Domains/Drivers/SyncDriverFromIntegrationTest.php` (mirror the existing tests' payload shape — they call `app(SyncDriverFromIntegration::class)->execute($teamId, $integrationId, $driverData)`):

```php
    public function test_sync_persists_normalized_e164_phone_on_create(): void
    {
        // ... arrange team + integration exactly as the existing create test does ...
        $driver = app(SyncDriverFromIntegration::class)->execute($teamId, $integrationId, [
            'external_id' => 'ext-phone-1',
            'name' => 'Jane Doe',
            'phone' => '+1 (415) 555-1234',
        ]);

        $this->assertSame('+14155551234', $driver->fresh()->phone);
    }

    public function test_sync_stores_null_when_phone_is_not_e164(): void
    {
        // ... same arrange ...
        $driver = app(SyncDriverFromIntegration::class)->execute($teamId, $integrationId, [
            'external_id' => 'ext-phone-2',
            'name' => 'Jane Doe',
            'phone' => '415-555-1234', // no country code → cannot normalize
        ]);

        $this->assertNull($driver->fresh()->phone);
    }

    public function test_sync_leaves_phone_untouched_when_absent_from_payload(): void
    {
        // ... same arrange; first create with a valid phone, then update without phone key ...
        $driver = app(SyncDriverFromIntegration::class)->execute($teamId, $integrationId, [
            'external_id' => 'ext-phone-3', 'name' => 'Jane', 'phone' => '+14155551234',
        ]);
        $this->assertSame('+14155551234', $driver->fresh()->phone);

        app(SyncDriverFromIntegration::class)->execute($teamId, $integrationId, [
            'external_id' => 'ext-phone-3', 'name' => 'Jane Roe', // no 'phone' key
        ]);
        $this->assertSame('+14155551234', $driver->fresh()->phone); // preserved
    }
```

> Copy the exact `$teamId`/`$integrationId` arrange block from the existing create/update tests in this file (TenantIntegration factory etc.).

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=SyncDriverFromIntegration`
Expected: the 3 new tests fail (phone never persisted).

- [ ] **Step 3: Map the phone in create and update**

In `app/Domains/Drivers/Actions/SyncDriverFromIntegration.php`, add `use App\Support\PhoneNumber;`.

In `createNewDriver()`, add `phone` to the `create([...])` array (after `employee_code`):

```php
            'employee_code' => $driverData['employee_code'] ?? null,
            'phone' => PhoneNumber::normalize($driverData['phone'] ?? null),
            'metadata_json' => $driverData['metadata'] ?? null,
```

In `updateExistingDriver()`, the update uses `array_filter([...])` which drops nulls (so a missing/invalid phone won't overwrite an existing one — exactly the "preserve when absent" behavior). Add `phone` to that array (after `employee_code`):

```php
            'employee_code' => $driverData['employee_code'] ?? null,
            'phone' => PhoneNumber::normalize($driverData['phone'] ?? null),
            'external_primary_id' => $driverData['external_id'],
```

> Because `array_filter` removes null values, an absent or non-E.164 phone on UPDATE leaves the stored phone untouched (test 3). On CREATE the column is simply set to null when not normalizable (test 2). This matches the silent-skip convention of the sync path (no throw).

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=SyncDriverFromIntegration`
Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Domains/Drivers/Actions/SyncDriverFromIntegration.php tests/Feature/Domains/Drivers/SyncDriverFromIntegrationTest.php
git commit -m "feat(drivers): persist normalized E.164 phone from integration sync"
```

---

## Task 5: Infraestructura OTP (rate-limiter, caché, acciones, meter)

**Files:**
- Create: `app/Support/OtpCacheKeys.php`, `app/Domains/Access/Data/OtpResult.php`, `app/Domains/Access/Actions/SendPhoneOtp.php`, `app/Domains/Access/Actions/VerifyPhoneOtp.php`, `database/seeders/OtpMeterSeeder.php`
- Modify: `app/Providers/FortifyServiceProvider.php`, `database/seeders/DatabaseSeeder.php`
- Test: covered by Task 6's feature test (the actions are exercised through the controller); add focused assertions there.

- [ ] **Step 1: Create `OtpCacheKeys`**

`app/Support/OtpCacheKeys.php`:

```php
<?php

namespace App\Support;

class OtpCacheKeys
{
    public const TTL_SECONDS = 300;

    public const MAX_ATTEMPTS = 5;

    public static function forUser(int $userId): string
    {
        return "otp:phone:{$userId}";
    }
}
```

- [ ] **Step 2: Create `OtpResult` DTO**

`app/Domains/Access/Data/OtpResult.php`:

```php
<?php

namespace App\Domains\Access\Data;

class OtpResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $reason = '',
    ) {}

    public static function success(): self
    {
        return new self(true);
    }

    public static function failure(string $reason): self
    {
        return new self(false, $reason);
    }
}
```

- [ ] **Step 3: Create `SendPhoneOtp`**

`app/Domains/Access/Actions/SendPhoneOtp.php`:

```php
<?php

namespace App\Domains\Access\Actions;

use App\Contracts\Notifications\ChannelDriverRegistry;
use App\Domains\Access\Data\OtpResult;
use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Models\User;
use App\Support\OtpCacheKeys;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;

class SendPhoneOtp
{
    public function __construct(
        private readonly ChannelDriverRegistry $drivers,
        private readonly RecordUsageEvent $recordUsage,
        private readonly RecordAuditEntry $audit,
    ) {}

    public function execute(User $user, int $teamId): OtpResult
    {
        $phone = (string) $user->phone;

        if (! PhoneNumber::isE164($phone)) {
            return OtpResult::failure('no_phone');
        }

        $channel = NotificationChannel::query()
            ->usableByTeam($teamId)
            ->where('channel_type', ChannelType::Sms)
            ->first();

        if ($channel === null) {
            $this->record($user, $teamId, 'phone_otp.send_failed', 'no_sms_channel');

            return OtpResult::failure('no_sms_channel');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put(OtpCacheKeys::forUser((int) $user->id), ['code' => $code, 'attempts' => 0], OtpCacheKeys::TTL_SECONDS);

        $rendered = new RenderedNotification(
            channelType: ChannelType::Sms,
            address: $phone,
            subject: null,
            body: "Tu código de verificación SAM es {$code}. Vence en 5 minutos.",
        );

        $result = $this->drivers->driverFor(ChannelType::Sms)->send($rendered, $channel);

        $this->recordUsage->execute(
            teamId: $teamId,
            meterCode: 'otp_sms_sent',
            quantity: 1,
            eventKey: "otp_sms_{$user->id}_".now()->valueOf(),
        );

        $this->record($user, $teamId, $result->success ? 'phone_otp.sent' : 'phone_otp.send_failed', $result->success ? 'sent' : 'delivery_failed');

        return $result->success ? OtpResult::success() : OtpResult::failure('delivery_failed');
    }

    private function record(User $user, int $teamId, string $action, string $outcome): void
    {
        // Audit the OUTCOME only — never the code or the full number (PII).
        $this->audit->execute(
            actorType: AuditActorType::User,
            actorId: (int) $user->id,
            action: $action,
            category: AuditCategory::Security,
            entityType: 'User',
            entityId: (int) $user->id,
            summary: "Phone OTP {$outcome} for user {$user->id}",
            teamId: $teamId,
            metadata: ['outcome' => $outcome],
        );
    }
}
```

- [ ] **Step 4: Create `VerifyPhoneOtp`**

`app/Domains/Access/Actions/VerifyPhoneOtp.php`:

```php
<?php

namespace App\Domains\Access\Actions;

use App\Domains\Access\Data\OtpResult;
use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Models\User;
use App\Support\OtpCacheKeys;
use Illuminate\Support\Facades\Cache;

class VerifyPhoneOtp
{
    public function __construct(
        private readonly RecordAuditEntry $audit,
    ) {}

    public function execute(User $user, int $teamId, string $code): OtpResult
    {
        $key = OtpCacheKeys::forUser((int) $user->id);
        $entry = Cache::get($key);

        if (! is_array($entry) || ! isset($entry['code'])) {
            return $this->fail($user, $teamId, 'expired');
        }

        if (($entry['attempts'] ?? 0) >= OtpCacheKeys::MAX_ATTEMPTS) {
            Cache::forget($key);

            return $this->fail($user, $teamId, 'too_many_attempts');
        }

        if (! hash_equals((string) $entry['code'], trim($code))) {
            Cache::put($key, ['code' => $entry['code'], 'attempts' => ($entry['attempts'] ?? 0) + 1], OtpCacheKeys::TTL_SECONDS);

            return $this->fail($user, $teamId, 'invalid_code');
        }

        $user->forceFill(['phone_verified_at' => now()])->save();
        Cache::forget($key);

        $this->audit($user, $teamId, 'phone_otp.verified', 'verified');

        return OtpResult::success();
    }

    private function fail(User $user, int $teamId, string $reason): OtpResult
    {
        $this->audit($user, $teamId, 'phone_otp.verify_failed', $reason);

        return OtpResult::failure($reason);
    }

    private function audit(User $user, int $teamId, string $action, string $outcome): void
    {
        $this->audit->execute(
            actorType: AuditActorType::User,
            actorId: (int) $user->id,
            action: $action,
            category: AuditCategory::Security,
            entityType: 'User',
            entityId: (int) $user->id,
            summary: "Phone OTP {$outcome} for user {$user->id}",
            teamId: $teamId,
            metadata: ['outcome' => $outcome],
        );
    }
}
```

> Note: `RecordAuditEntry` is idempotent by `signature` (built from action+entity+team). Multiple failed attempts share the same auto-signature and would collapse to one row — acceptable for "audit the outcome". If per-attempt rows are desired, pass a distinct `signature` per call; not required here.

- [ ] **Step 5: Create `OtpMeterSeeder` and register it**

`database/seeders/OtpMeterSeeder.php` (copy `NotificationMeterSeeder`):

```php
<?php

namespace Database\Seeders;

use App\Domains\Tenancy\Enums\AggregationType;
use App\Domains\Tenancy\Enums\ResetPeriod;
use App\Domains\Tenancy\Models\UsageMeter;
use Illuminate\Database\Seeder;

class OtpMeterSeeder extends Seeder
{
    public function run(): void
    {
        UsageMeter::query()->updateOrCreate(
            ['code' => 'otp_sms_sent'],
            [
                'name' => 'OTP SMS Sent',
                'description' => 'Number of phone-verification OTP SMS messages sent.',
                'unit' => 'count',
                'aggregation_type' => AggregationType::Sum,
                'is_billable' => true,
                'reset_period' => ResetPeriod::Monthly,
            ],
        );
    }
}
```

Register it in `database/seeders/DatabaseSeeder.php` — find where `NotificationMeterSeeder::class` is called and add `OtpMeterSeeder::class` next to it (same `$this->call([...])` group). If `NotificationMeterSeeder` is not auto-called there, add `$this->call(OtpMeterSeeder::class);` alongside the other meter seeders.

- [ ] **Step 6: Add the `otp` rate-limiter**

In `app/Providers/FortifyServiceProvider.php`, inside `configureRateLimiting()`, add (mirroring `two-factor`):

```php
        RateLimiter::for('otp', function (Request $request) {
            return Limit::perMinute(5)->by((string) ($request->user()?->id ?: $request->ip()));
        });
```

- [ ] **Step 7: Verify it boots (no test yet — exercised in Task 6)**

Run: `php artisan test --compact --filter=ProfileUpdateTest` (sanity: app still boots with new providers/actions).
Expected: PASS.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/OtpCacheKeys.php app/Domains/Access/Data/OtpResult.php app/Domains/Access/Actions/SendPhoneOtp.php app/Domains/Access/Actions/VerifyPhoneOtp.php database/seeders/OtpMeterSeeder.php database/seeders/DatabaseSeeder.php app/Providers/FortifyServiceProvider.php
git commit -m "feat(access): OTP send/verify actions, otp rate limiter and usage meter"
```

---

## Task 6: Endpoints de verificación de teléfono

**Files:**
- Create: `app/Http/Controllers/Settings/PhoneVerificationController.php`
- Modify: `routes/settings.php`
- Test: `tests/Feature/Settings/PhoneVerificationTest.php`

- [ ] **Step 1: Write the failing feature test**

`tests/Feature/Settings/PhoneVerificationTest.php`:

```php
<?php

namespace Tests\Feature\Settings;

use App\Contracts\Notifications\ChannelDriverRegistry;
use App\Domains\Notifications\Channels\SmsNotificationDriver;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Models\User;
use App\Support\OtpCacheKeys;
use Database\Seeders\OtpMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(OtpMeterSeeder::class);
    }

    private function fakeSmsSuccess(): void
    {
        // Make the SMS driver report success without calling Twilio.
        $driver = Mockery::mock(SmsNotificationDriver::class);
        $driver->shouldReceive('send')->andReturn(DeliveryResult::success(providerMessageId: 'SM_test'));
        $registry = Mockery::mock(ChannelDriverRegistry::class);
        $registry->shouldReceive('driverFor')->with(ChannelType::Sms)->andReturn($driver);
        $this->app->instance(ChannelDriverRegistry::class, $registry);
    }

    public function test_send_stores_code_and_dispatches_sms(): void
    {
        $this->fakeSmsSuccess();
        $user = User::factory()->create(['phone' => '+5215555550123']);
        $team = $user->currentTeam;
        NotificationChannel::factory()->sms()->create(['team_id' => $team->id, 'is_active' => true, 'channel_type' => ChannelType::Sms]);
        $this->actingAs($user);

        $this->post(route('phone-verification.send'))->assertSessionHasNoErrors();

        $this->assertNotNull(Cache::get(OtpCacheKeys::forUser($user->id)));
    }

    public function test_send_without_sms_channel_fails_clearly(): void
    {
        $user = User::factory()->create(['phone' => '+5215555550123']);
        $this->actingAs($user);

        $this->post(route('phone-verification.send'))->assertSessionHas('errors');
        $this->assertNull(Cache::get(OtpCacheKeys::forUser($user->id)));
    }

    public function test_verify_with_correct_code_sets_verified_at(): void
    {
        $user = User::factory()->create(['phone' => '+5215555550123']);
        $this->actingAs($user);
        Cache::put(OtpCacheKeys::forUser($user->id), ['code' => '123456', 'attempts' => 0], 300);

        $this->patch(route('phone-verification.verify'), ['code' => '123456'])->assertSessionHasNoErrors();

        $this->assertNotNull($user->fresh()->phone_verified_at);
        $this->assertNull(Cache::get(OtpCacheKeys::forUser($user->id)));
    }

    public function test_verify_with_wrong_code_increments_attempts_and_does_not_verify(): void
    {
        $user = User::factory()->create(['phone' => '+5215555550123']);
        $this->actingAs($user);
        Cache::put(OtpCacheKeys::forUser($user->id), ['code' => '123456', 'attempts' => 0], 300);

        $this->patch(route('phone-verification.verify'), ['code' => '000000'])->assertSessionHas('errors');

        $this->assertNull($user->fresh()->phone_verified_at);
        $this->assertSame(1, Cache::get(OtpCacheKeys::forUser($user->id))['attempts']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=PhoneVerificationTest`
Expected: FAIL — route names not defined.

- [ ] **Step 3: Create the controller**

`app/Http/Controllers/Settings/PhoneVerificationController.php`:

```php
<?php

namespace App\Http\Controllers\Settings;

use App\Domains\Access\Actions\SendPhoneOtp;
use App\Domains\Access\Actions\VerifyPhoneOtp;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PhoneVerificationController extends Controller
{
    public function send(Request $request, SendPhoneOtp $sendPhoneOtp): RedirectResponse
    {
        $user = $request->user();
        $result = $sendPhoneOtp->execute($user, (int) $user->current_team_id);

        if (! $result->ok) {
            return back()->withErrors(['phone' => $this->messageFor($result->reason)]);
        }

        return back()->with('status', 'phone-otp-sent');
    }

    public function verify(Request $request, VerifyPhoneOtp $verifyPhoneOtp): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $user = $request->user();
        $result = $verifyPhoneOtp->execute($user, (int) $user->current_team_id, $validated['code']);

        if (! $result->ok) {
            return back()->withErrors(['code' => $this->messageFor($result->reason)]);
        }

        return back()->with('status', 'phone-verified');
    }

    private function messageFor(string $reason): string
    {
        return match ($reason) {
            'no_phone' => 'Agrega un teléfono en formato E.164 antes de verificar.',
            'no_sms_channel' => 'No hay un canal SMS configurado; contacta a tu administrador.',
            'delivery_failed' => 'No pudimos enviar el SMS. Intenta de nuevo en un momento.',
            'expired' => 'El código expiró. Solicita uno nuevo.',
            'too_many_attempts' => 'Demasiados intentos. Solicita un código nuevo.',
            'invalid_code' => 'El código no es correcto.',
            default => 'No se pudo completar la verificación.',
        };
    }
}
```

- [ ] **Step 4: Add the routes**

In `routes/settings.php`, inside the FIRST group `Route::middleware(['auth'])->group(...)` (next to `profile.edit`/`profile.update`, NOT under `{current_team}` and NOT in the `verified` group), add:

```php
    Route::post('settings/phone/verification', [PhoneVerificationController::class, 'send'])
        ->middleware('throttle:otp')
        ->name('phone-verification.send');
    Route::patch('settings/phone/verification', [PhoneVerificationController::class, 'verify'])
        ->middleware('throttle:otp')
        ->name('phone-verification.verify');
```

Add the import at the top: `use App\Http\Controllers\Settings\PhoneVerificationController;`.

- [ ] **Step 5: Regenerate Wayfinder types (routes changed)**

Run: `php artisan wayfinder:generate --with-form`
Expected: regenerates `resources/js/routes`/`actions` (gitignored in worktree). No commit needed for generated files; this unblocks the frontend in Fase 2b.

- [ ] **Step 6: Run the feature test**

Run: `php artisan test --compact --filter=PhoneVerificationTest`
Expected: PASS (4 tests).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Settings/PhoneVerificationController.php routes/settings.php tests/Feature/Settings/PhoneVerificationTest.php
git commit -m "feat(settings): phone OTP send/verify endpoints (throttled)"
```

---

## Task 7: Gates de cierre (backend 2a)

**Files:** none (verification only)

- [ ] **Step 1: Targeted suites**

Run: `php artisan test --compact tests/Feature/Settings tests/Feature/Domains/Drivers tests/Unit/Support tests/Unit/Rules`
Expected: all green.

- [ ] **Step 2: Full suite**

Run: `php artisan test --compact`
Expected: green. Page-render tests need `public/build` (already built earlier); if any `ViteManifestNotFoundException` appears, run `npm run build` once and re-run — those are environmental, not logic failures.

- [ ] **Step 3: Seeder integrity**

Run: `php artisan migrate:fresh --seed` against the test sqlite (or a scratch sqlite), confirming `OtpMeterSeeder` runs without error and `otp_sms_sent` meter exists.

- [ ] **Step 4: Style gate**

Run: `vendor/bin/pint --test app/Support app/Rules app/Domains/Access app/Http/Controllers/Settings app/Http/Requests/Drivers app/Domains/Drivers database/seeders routes`
Expected: clean.

---

## Self-Review

**Spec coverage (§3.5 + the validation parts of §3.1):**
- E.164 validation centralized + reusable → Task 1 ✓
- User profile phone validation + re-verification reset → Task 2 ✓
- Driver `mobile_phone` contact E.164 validation → Task 3 ✓
- Driver phone persisted + normalized from sync (scope expansion approved by user) → Task 4 ✓
- OTP send/verify (cache+TTL, attempt cap), `otp` rate limiter, `otp_sms_sent` meter, audit of outcomes (not the code), no-SMS-channel → clear failure → Tasks 5–6 ✓
- Verification OPTIONAL (no gating added) → respected ✓
- Frontend profile UI → deferred to Fase 2b (separate plan) ✓

**Placeholder scan:** Task 3's test arrange and Task 4's test arrange say "copy the setup from DriverPolicyTest / existing sync tests" — this is a deliberate instruction to mirror an existing, concrete pattern (the exact role/factory boilerplate lives in those files), not an unfilled blank. The behavior assertions are fully specified. Everything else is complete code.

**Type/name consistency:** `OtpResult::success()/failure(string)` defined in Task 5, consumed in Task 6 with `->ok`/`->reason`. `PhoneNumber::isE164()/normalize()` defined Task 1, used in Tasks 3/4/5. `OtpCacheKeys::forUser()/TTL_SECONDS/MAX_ATTEMPTS` defined Task 5, used in Tasks 5/6 tests. Route names `phone-verification.send|verify` defined Task 6, used in its test. `RecordAuditEntry::execute(...)` and `RecordUsageEvent::execute(...)` signatures match the real classes verified in the repo.

**Risk note:** `SmsNotificationDriver` is final-ish only if declared so — the test mocks it via `Mockery::mock(SmsNotificationDriver::class)` and rebinds `ChannelDriverRegistry`; if the driver can't be mocked directly, mock `TwilioMessenger` instead (constructor dep) — both avoid real Twilio calls. The implementer should pick whichever the test proves works.
