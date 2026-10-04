<?php

namespace Database\Seeders;

use App\Domains\Context\Listeners\RequestIncidentMediaOnContextBuilt;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\Concerns\DevelopmentOnly;
use Illuminate\Database\Seeder;

/**
 * Base de las pruebas end-to-end de Playwright (`tests/e2e`): el sembrado de
 * desarrollo (ServiExpress JC, sus usuarios y reglas de pánico) más una
 * integración Samsara con endpoint de webhook y Secret Key conocidos, para que
 * el E2E mande pánicos firmados como Samsara por el pipeline completo.
 *
 * El super-admin entra con 2FA ya confirmado (secreto TOTP fijo).
 *
 * Sin red: la petición automática de media del pánico se apaga (llamaría a la
 * API de Samsara con una credencial falsa).
 */
class E2eSeeder extends Seeder
{
    use DevelopmentOnly;

    public const TEAM_SLUG = 'serviexpress-jc';

    /** Ruta del webhook (`/api/webhooks/{url}`); no es secreta. */
    public const WEBHOOK_URL = 'e2e-samsara';

    /** Llave de firma sólo para la base E2E, nunca un valor real. */
    public const WEBHOOK_SECRET = 'e2e-webhook-secret-not-real';

    /**
     * Secreto TOTP (base32) del super-admin: la consola exige 2FA y el E2E
     * calcula el código con él. Sólo para la base E2E.
     */
    public const SUPER_ADMIN_TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    public function run(): void
    {
        if ($this->skipInProduction()) {
            return;
        }

        $this->call(DatabaseSeeder::class);

        $team = Team::query()->where('slug', self::TEAM_SLUG)->firstOrFail();
        $provider = IntegrationProvider::query()->where('code', 'samsara')->firstOrFail();

        $integration = TenantIntegration::withoutGlobalScopes()->updateOrCreate(
            ['team_id' => $team->id, 'provider_id' => $provider->id],
            [
                'name' => 'Samsara',
                'status' => TenantIntegrationStatus::Active,
                'auth_type' => 'api_key',
                'credentials_encrypted' => 'e2e-token-not-real',
            ],
        );

        WebhookEndpoint::query()->updateOrCreate(
            ['tenant_integration_id' => $integration->id],
            [
                'url' => self::WEBHOOK_URL,
                'secret' => self::WEBHOOK_SECRET,
                'secret_configured_at' => now(),
                'status' => 'active',
            ],
        );

        TenantSetting::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('setting_key', RequestIncidentMediaOnContextBuilt::SETTING_KEY)
            ->update(['value_json' => ['value' => false]]);

        User::query()->where('email', SuperAdminSeeder::SUPER_ADMIN_EMAIL)->firstOrFail()->forceFill([
            'two_factor_secret' => encrypt(self::SUPER_ADMIN_TOTP_SECRET),
            'two_factor_recovery_codes' => encrypt((string) json_encode([])),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }
}
