<?php

namespace Tests\Feature\Security;

use App\Domains\Analytics\Models\AnalyticsSnapshot;
use App\Domains\Analytics\Models\ReportExecution;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Copilot\Enums\CopilotMessageRole;
use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Models\InvoiceSnapshot;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Enums\TeamRole;
use App\Http\Middleware\EnsureTeamMembership;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Faker\Provider\Base;
use Faker\Provider\Lorem;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Barrido cross-tenant permanente de TODAS las rutas web/api (auditoría de
 * seguridad 2026-09-27, que se hizo a mano y nunca se commiteó).
 *
 * Dos tenants, A y B, con un registro de cada modelo bindeable por ruta. Un
 * owner de A recorre cada ruta tenant-scoped en hasta tres variantes:
 *
 *  1. `foreign-team`: el team de la URL es B y los parámetros son de B
 *     (no es miembro → nunca 2xx).
 *  2. `foreign-records`: el team de la URL es A y los parámetros son de B
 *     (route-model binding / lookups manuales → nunca 2xx).
 *  3. `own`: todo de A. Control de que el fixture realmente bindea (un GET no
 *     puede dar 403/404, si no las variantes anteriores serían vacuas) y
 *     barrido de listados: ninguna página propia puede traer datos de B.
 *
 * En cada petición, sea cual sea la variante, se exige además:
 *  - ninguna fila de B cambió (inserción, borrado o update en cualquier tabla
 *    con `team_id`);
 *  - si la respuesta no es un error (2xx/3xx que no sea un rechazo de
 *    validación), no se hidrató ningún modelo de B (detecta un binding sin
 *    scope aunque el controlador no lo devuelva) y el cuerpo no contiene
 *    identificadores de B (slug, nombre, uuids, textos, `team_id`).
 *
 * Toda ruta registrada está o barrida o en una exclusión razonada: una ruta
 * nueva sin clasificar, un parámetro nuevo sin fixture o una exclusión que ya
 * no existe hacen fallar el test. Las rutas nuevas bajo `/{current_team}` o
 * con un modelo tenant-scoped tipado en el controlador se barren solas.
 *
 * Grupo `security-sweep`: CI corre la suite completa (`php artisan test
 * --parallel`), así que el grupo entra siempre; no lo excluyas.
 */
#[Group('security-sweep')]
class CrossTenantRouteSweepTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Prefijos de URI fuera del barrido de tenant, con su razón.
     *
     * @var array<string, string>
     */
    private const EXCLUDED_PREFIXES = [
        'admin/' => 'Consola super-admin (ensure.super_admin): cruza tenants a propósito; su acceso se prueba en tests/Feature/Admin.',
        'horizon' => 'Horizon: dashboard de plataforma tras el gate viewHorizon, sin datos de tenant por URL.',
        'api/webhooks/' => 'Webhooks firmados: el tenant sale del WebhookEndpoint / firma Twilio en DB, nunca del usuario (tests de Ingestion y Notifications).',
        'storage/' => 'Disco local servido por Laravel sólo con URL firmada temporal.',
    ];

    /**
     * Rutas puntuales fuera del barrido de tenant, con su razón. `*` = todos
     * los verbos de esa URI.
     *
     * @var array<string, string>
     */
    private const EXCLUDED_ROUTES = [
        'GET /' => 'Landing pública.',
        'GET up' => 'Health check público.',
        'GET demo' => 'Formulario público "Pedir una demo": sin datos de tenant.',
        'POST demo' => 'Formulario público "Pedir una demo": crea una fila de plataforma (sin team_id) que sólo lee la consola de super-admin (DemoRequestTest).',
        'GET bienvenida/{token}' => 'Alta de cliente por enlace con token secreto (público por diseño).',
        'POST bienvenida' => 'Alta de cliente por enlace con token secreto (público por diseño).',
        'GET invitations/{invitation}' => 'Invitación por código secreto; aceptar exige que el email coincida (tests de InvitationAcceptance).',
        'POST invitations/{invitation}/accept' => 'Invitación por código secreto; aceptar exige que el email coincida (tests de InvitationAcceptance).',
        'POST invitations/{invitation}/register' => 'Invitación por código secreto; registrarse exige que el email coincida (tests de InvitationAcceptance).',
        'GET broadcasting/auth' => 'Autorización de canales: se prueba por canal de routes/channels.php (tests *ChannelAuthorization*).',
        'POST broadcasting/auth' => 'Autorización de canales: se prueba por canal de routes/channels.php (tests *ChannelAuthorization*).',
        // Fortify / autenticación: sin tenant, actúan sobre el usuario autenticado o anónimo.
        'GET login' => 'Autenticación (Fortify).',
        'POST login' => 'Autenticación (Fortify).',
        'POST logout' => 'Autenticación (Fortify).',
        'GET forgot-password' => 'Autenticación (Fortify).',
        'POST forgot-password' => 'Autenticación (Fortify).',
        'GET reset-password/{token}' => 'Autenticación (Fortify), token de reseteo.',
        'POST reset-password' => 'Autenticación (Fortify).',
        'GET two-factor-challenge' => 'Autenticación (Fortify).',
        'POST two-factor-challenge' => 'Autenticación (Fortify).',
        'GET email/verify' => 'Verificación de email del usuario autenticado.',
        'GET email/verify/{id}/{hash}' => 'Verificación de email por URL firmada.',
        'POST email/verification-notification' => 'Verificación de email del usuario autenticado.',
        'GET user/confirm-password' => 'Confirmación de contraseña del usuario autenticado.',
        'POST user/confirm-password' => 'Confirmación de contraseña del usuario autenticado.',
        'GET user/confirmed-password-status' => 'Confirmación de contraseña del usuario autenticado.',
        'POST user/confirmed-two-factor-authentication' => '2FA del usuario autenticado.',
        'POST user/two-factor-authentication' => '2FA del usuario autenticado.',
        'DELETE user/two-factor-authentication' => '2FA del usuario autenticado.',
        'GET user/two-factor-qr-code' => '2FA del usuario autenticado.',
        'GET user/two-factor-recovery-codes' => '2FA del usuario autenticado.',
        'POST user/two-factor-recovery-codes' => '2FA del usuario autenticado.',
        'GET user/two-factor-secret-key' => '2FA del usuario autenticado.',
        // Ajustes personales: sin tenant en la URL, actúan sobre $request->user().
        '* settings' => 'Route::redirect (cualquier verbo) a settings/profile.',
        'GET settings/appearance' => 'Ajuste personal del usuario autenticado.',
        'GET settings/notifications' => 'Preferencias personales del usuario autenticado.',
        'PUT settings/notifications' => 'Preferencias personales del usuario autenticado.',
        'POST settings/push-subscriptions' => 'Dispositivo personal del usuario autenticado, sin parámetros de ruta (el aislamiento por tenant se prueba en los tests de PushSubscription).',
        'DELETE settings/push-subscriptions' => 'Dispositivo personal del usuario autenticado, sin parámetros de ruta (el aislamiento por tenant se prueba en los tests de PushSubscription).',
        'PUT settings/password' => 'Ajuste personal del usuario autenticado.',
        'POST settings/phone/verification' => 'Teléfono del usuario autenticado.',
        'PATCH settings/phone/verification' => 'Teléfono del usuario autenticado.',
        'GET settings/profile' => 'Perfil del usuario autenticado.',
        'PATCH settings/profile' => 'Perfil del usuario autenticado.',
        'DELETE settings/profile' => 'Perfil del usuario autenticado.',
        'GET settings/security' => 'Seguridad del usuario autenticado.',
        'GET settings/teams' => 'Lista los teams de los que el usuario ES miembro.',
        'POST settings/teams' => 'Crea un team nuevo del usuario autenticado.',
    ];

    /**
     * Parámetros de ruta escalares (sin modelo en la firma): de qué modelo
     * sale el valor de cada team y qué columna va en la URL.
     *
     * `selector` = el valor no identifica un registro sino que filtra (un
     * tipo de snapshot): sólo B tiene registro y todas las variantes usan su
     * valor; un 2xx está permitido, pero nunca con datos de B.
     *
     * @var array<string, array{class: class-string<Model>, field: string, selector: bool}>
     */
    private const SCALAR_PARAMS = [
        'invoice' => ['class' => InvoiceSnapshot::class, 'field' => 'id', 'selector' => false],
        'type' => ['class' => AnalyticsSnapshot::class, 'field' => 'snapshot_type', 'selector' => true],
    ];

    private const TENANT_PARAMS = ['current_team', 'team'];

    /**
     * Columnas de texto cuyo valor en B es huella (con 8 caracteres o más).
     *
     * @var list<string>
     */
    private const FINGERPRINT_TEXT_COLUMNS = ['name', 'title', 'subject', 'display_name', 'external_id', 'label'];

    private Team $teamA;

    private Team $teamB;

    private User $userA;

    private User $userB;

    /** @var array<class-string<Model>, array{a?: Model, b: Model}> registros por team */
    private array $records = [];

    /** @var list<string> */
    private array $fingerprintsB = [];

    /** @var list<string> */
    private array $teamTables = [];

    /** @var list<string> */
    private array $hydratedB = [];

    public function test_no_route_serves_or_mutates_another_tenants_data(): void
    {
        $this->bootTenants();

        [$swept, $unclassified, $missingFixtures] = $this->classifyRoutes();

        $this->assertSame([], $unclassified, "Rutas sin clasificar en el barrido cross-tenant.\n".
            "Si llevan datos de tenant, ponlas bajo /{current_team} con EnsureTeamMembership (se barren solas);\n".
            "si no, añádelas a EXCLUDED_ROUTES con su razón:\n  ".implode("\n  ", $unclassified));
        $this->assertSame([], $missingFixtures, "Parámetros de ruta sin fixture en el barrido cross-tenant\n".
            "(tipa el modelo en la firma del controlador o añádelo a SCALAR_PARAMS):\n  ".implode("\n  ", $missingFixtures));

        // Todos los fixtures antes de la primera petición: las variantes `own`
        // de los DELETE borran registros de A que otras rutas necesitan.
        foreach ($swept as $entry) {
            foreach ($entry['params'] as $param) {
                $this->record($param['class'], 'b');
            }
        }

        $this->listenForForeignHydration();

        $failures = [];
        $requests = 0;

        // Orden por fases para que ninguna petición deje vacua a otra: primero
        // todo lo que no muta; después las variantes ajenas de lo que escribe
        // (A sigue intacto); al final las variantes propias que escriben, con
        // los DELETE (borrar un miembro, el propio team…) en último lugar.
        $probes = [];

        foreach ($swept as $entry) {
            foreach ($this->variantsFor($entry) as $variantName => $variant) {
                $phase = match (true) {
                    $entry['method'] === 'GET' => 0,
                    $variantName !== 'own' => 1,
                    $entry['method'] !== 'DELETE' => 2,
                    default => 3,
                };

                $probes[] = ['phase' => $phase, 'entry' => $entry, 'variantName' => $variantName, 'variant' => $variant];
            }
        }

        usort($probes, fn (array $x, array $y) => [$x['phase'], $x['entry']['key']] <=> [$y['phase'], $y['entry']['key']]);

        foreach ($probes as $probe) {
            $requests++;
            $failure = $this->probe($probe['entry'], $probe['variantName'], $probe['variant']);

            if ($failure !== null) {
                $failures[] = "{$probe['entry']['key']} [{$probe['variantName']}]: {$failure}";
            }
        }

        $this->assertSame([], $failures, "Fugas cross-tenant (o barrido vacuo) en {$requests} peticiones:\n  ".implode("\n  ", $failures));

        $this->assertGreaterThan(150, count($swept), 'El barrido cubre sospechosamente pocas rutas: ¿se rompió la clasificación?');
        $this->addToAssertionCount($requests);
    }

    /**
     * El barrido sólo vale si sus detectores disparan: tres rutas canario,
     * registradas sólo en este proceso de test, con las fugas que buscamos.
     */
    public function test_sweep_detects_leaky_routes(): void
    {
        $this->bootTenants();
        $this->listenForForeignHydration();

        $middleware = ['web', 'auth', EnsureTeamMembership::class];
        $incidentParam = ['class' => Incident::class, 'field' => 'id', 'selector' => false];
        $teamParam = ['class' => Team::class, 'field' => 'slug', 'selector' => false];

        $this->record(Incident::class, 'b');

        // Lookup sin scope por id: sirve el incidente de B a A.
        $byId = RouteFacade::middleware($middleware)->get('{current_team}/__sweep-canary/{incident}', fn (Team $current_team, string $incident) => response()->json(
            Incident::query()->withoutGlobalScopes()->findOrFail($incident),
        ));
        // Listado sin scope: la página propia de A arrastra filas de B.
        $listing = RouteFacade::middleware($middleware)->get('{current_team}/__sweep-canary-list', fn (Team $current_team) => response()->json(
            Incident::query()->withoutGlobalScopes()->get(),
        ));
        // Escritura sin scope que además responde 403: el estado HTTP no salva.
        $write = RouteFacade::middleware($middleware)->post('{current_team}/__sweep-canary-write/{incident}', function (Team $current_team, string $incident) {
            Incident::query()->withoutGlobalScopes()->whereKey($incident)->update(['title' => 'pisado por A']);

            abort(403);
        });

        $foreignRecords = $this->probe(
            ['key' => 'GET canary', 'method' => 'GET', 'route' => $byId, 'params' => ['current_team' => $teamParam, 'incident' => $incidentParam]],
            'foreign-records',
            ['tenant' => 'a', 'records' => 'b'],
        );
        $ownListing = $this->probe(
            ['key' => 'GET canary-list', 'method' => 'GET', 'route' => $listing, 'params' => ['current_team' => $teamParam]],
            'own',
            ['tenant' => 'a', 'records' => 'a'],
        );
        $foreignWrite = $this->probe(
            ['key' => 'POST canary-write', 'method' => 'POST', 'route' => $write, 'params' => ['current_team' => $teamParam, 'incident' => $incidentParam]],
            'foreign-records',
            ['tenant' => 'a', 'records' => 'b'],
        );

        $this->assertStringContainsString('cargado modelos de B', (string) $foreignRecords);
        $this->assertStringContainsString('cargado modelos de B', (string) $ownListing);
        $this->assertStringContainsString('modificó filas de B en: incidents', (string) $foreignWrite);

        // Y el detector de identificadores, aislado del de hidratación.
        $this->hydratedB = [];
        $leaked = $this->leakedFingerprint(
            TestResponse::fromBaseResponse(response()->json(['team' => ['team_id' => $this->teamB->id]])),
            '/canary',
            [],
        );
        $this->assertSame("team_id {$this->teamB->id}", $leaked);

        // Un texto de B cuenta como fuga cuando aparece como valor completo,
        // en JSON o escapado en el data-page de Inertia…
        $this->fingerprintsB = ['officia dolorum'];
        $this->assertSame('officia dolorum', $this->leakedFingerprint(
            TestResponse::fromBaseResponse(response()->json(['name' => 'officia dolorum'])), '/canary', [],
        ));
        $this->assertSame('officia dolorum', $this->leakedFingerprint(
            TestResponse::fromBaseResponse(response('<div data-page="'.htmlspecialchars((string) json_encode(['title' => 'officia dolorum']), ENT_QUOTES).'"></div>')), '/canary', [],
        ));
        // …pero no cuando es subcadena casual de un texto de A.
        $this->assertNull($this->leakedFingerprint(
            TestResponse::fromBaseResponse(response()->json(['description' => 'Sunt officia dolorum quia'])), '/canary', [],
        ));
    }

    /**
     * Regresión (pre-push 2026-10-04): el lorem de faker de B («et
     * consequatur») salió igual en un registro de A y el listado propio de A
     * se reportó como fuga. Las huellas de B tienen que ser únicas por
     * construcción, sin perder la detección de una fuga real.
     */
    public function test_tenant_a_record_with_the_same_faker_text_as_b_is_not_a_leak(): void
    {
        $this->bootTenants();
        $this->listenForForeignHydration();

        $lorem = new class(fake()) extends Base
        {
            public ?string $next = null;

            /**
             * @return list<string>|string
             */
            public function words(int $nb = 3, bool $asText = false): array|string
            {
                if ($asText && $this->next !== null) {
                    [$text, $this->next] = [$this->next, null];

                    return $text;
                }

                return Lorem::words($nb, $asText);
            }
        };
        fake()->addProvider($lorem);

        // B sale de la factory con «et consequatur»; su gemelo de A, igual.
        $lorem->next = 'et consequatur';
        $b = $this->record(DecisionRule::class, 'b');
        $this->assertNull($lorem->next, 'La factory de DecisionRule ya no usa words(): elige otro modelo para el choque.');

        $lorem->next = 'et consequatur';
        $twinA = $this->makeRecord(DecisionRule::class, $this->teamA);
        $this->assertSame('et consequatur', $twinA->getAttribute('name'));

        // La huella de B sigue existiendo, pero ya no es el lorem compartido.
        $this->assertContains($b->getAttribute('name'), $this->fingerprintsB);
        $this->assertNotContains('et consequatur', $this->fingerprintsB);

        $route = RouteFacade::getRoutes()->getByName('api.decisions.rules.index');
        $this->assertNotNull($route);
        $teamParam = ['class' => Team::class, 'field' => 'slug', 'selector' => false];

        $failure = $this->probe(
            ['key' => 'GET api/{current_team}/decisions/rules', 'method' => 'GET', 'route' => $route, 'params' => ['current_team' => $teamParam]],
            'own',
            ['tenant' => 'a', 'records' => 'a'],
        );
        $this->assertNull($failure);

        // El listado sí sirve al gemelo de A (la prueba no es vacua)…
        $this->actingAs($this->userA)
            ->getJson("/api/{$this->teamA->slug}/decisions/rules")
            ->assertOk()
            ->assertSee('et consequatur');

        // …y la regla de B, si se colara, se seguiría detectando.
        $this->assertSame($b->getAttribute('name'), $this->leakedFingerprint(
            TestResponse::fromBaseResponse(response()->json(['name' => $b->getAttribute('name')])), '/canary', [],
        ));
    }

    public function test_every_current_team_route_enforces_membership(): void
    {
        $missing = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => in_array('current_team', $route->parameterNames(), true))
            ->reject(fn (Route $route) => in_array(EnsureTeamMembership::class, $route->gatherMiddleware(), true))
            ->map(fn (Route $route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame([], $missing, "Rutas /{current_team} sin EnsureTeamMembership:\n  ".implode("\n  ", $missing));
    }

    public function test_exclusions_still_match_registered_routes(): void
    {
        $registered = collect(RouteFacade::getRoutes()->getRoutes())
            ->flatMap(fn (Route $route) => collect($route->methods())
                ->reject(fn (string $method) => $method === 'HEAD')
                ->map(fn (string $method) => "{$method} {$route->uri()}")
                ->push("* {$route->uri()}"))
            ->all();

        $stale = array_values(array_diff(array_keys(self::EXCLUDED_ROUTES), $registered));

        $this->assertSame([], $stale, "Exclusiones del barrido que ya no existen (bórralas):\n  ".implode("\n  ", $stale));
    }

    private function bootTenants(): void
    {
        $this->seed(DatabaseSeeder::class);

        Queue::fake();
        Notification::fake();
        Mail::fake();
        Http::fake();
        Storage::fake('rustfs');

        $this->withoutMiddleware([ThrottleRequests::class, ThrottleRequestsWithRedis::class]);

        $this->userA = User::factory()->create();
        $this->teamA = $this->userA->currentTeam;

        $this->userB = User::factory()->create();
        $this->teamB = $this->userB->currentTeam;
        $this->teamB->forceFill(['name' => 'Inquilino Bravo Zq8x', 'slug' => 'inquilino-bravo-zq8x'])->save();

        // Segundo miembro de A: control de las rutas de miembros con un
        // usuario que sí pertenece al team de la URL.
        $memberA = User::factory()->create();
        $this->teamA->members()->attach($memberA, ['role' => TeamRole::Member->value]);

        // Features opt-in (sin fila = apagada): encendidas en ambos teams para
        // que sus rutas se barran de verdad y no respondan 403 vacuo.
        foreach ([$this->teamA, $this->teamB] as $team) {
            TenantFeature::factory()->create(['team_id' => $team->id, 'feature_key' => HosMonitoringConfig::FEATURE_KEY, 'enabled' => true]);
        }

        $this->records[User::class] = ['a' => $memberA, 'b' => $this->userB];
        $this->records[Team::class] = ['a' => $this->teamA, 'b' => $this->teamB];
        $this->records[Membership::class] = [
            'a' => Membership::query()->where('team_id', $this->teamA->id)->where('user_id', $memberA->id)->firstOrFail(),
            'b' => Membership::query()->where('team_id', $this->teamB->id)->where('user_id', $this->userB->id)->firstOrFail(),
        ];

        $this->teamTables = collect(Schema::getTables())
            ->pluck('name')
            ->filter(fn (string $table) => Schema::hasColumn($table, 'team_id'))
            ->values()
            ->all();
    }

    /**
     * @return array{0: list<array{key: string, method: string, route: Route, params: array<string, array{class: class-string<Model>, field: string, selector: bool}>}>, 1: list<string>, 2: list<string>}
     */
    private function classifyRoutes(): array
    {
        $swept = [];
        $unclassified = [];
        $missingFixtures = [];

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $key = "{$method} {$route->uri()}";

                if ($this->isExcluded($route, $key)) {
                    continue;
                }

                $params = [];
                $tenantScoped = false;

                foreach ($route->parameterNames() as $name) {
                    $param = $this->resolveParam($route, $name);

                    if ($param === null) {
                        $missingFixtures[] = "{$key} → {{$name}}";

                        continue;
                    }

                    $params[$name] = $param;
                    $tenantScoped = $tenantScoped
                        || in_array($name, self::TENANT_PARAMS, true)
                        || Schema::hasColumn((new $param['class'])->getTable(), 'team_id');
                }

                if (count($params) < count($route->parameterNames())) {
                    continue;
                }

                if (! $tenantScoped) {
                    $unclassified[] = $key;

                    continue;
                }

                $swept[] = ['key' => $key, 'method' => $method, 'route' => $route, 'params' => $params];
            }
        }

        return [$swept, array_values(array_unique($unclassified)), array_values(array_unique($missingFixtures))];
    }

    private function isExcluded(Route $route, string $key): bool
    {
        if (array_key_exists($key, self::EXCLUDED_ROUTES) || array_key_exists("* {$route->uri()}", self::EXCLUDED_ROUTES)) {
            return true;
        }

        foreach (array_keys(self::EXCLUDED_PREFIXES) as $prefix) {
            if (Str::startsWith($route->uri(), $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{class: class-string<Model>, field: string, selector: bool}|null
     */
    private function resolveParam(Route $route, string $name): ?array
    {
        if (in_array($name, self::TENANT_PARAMS, true)) {
            return ['class' => Team::class, 'field' => 'slug', 'selector' => false];
        }

        foreach ($route->signatureParameters(['subClass' => UrlRoutable::class]) as $parameter) {
            $type = $parameter->getType();

            if ($parameter->getName() === $name && $type instanceof ReflectionNamedType && is_subclass_of($type->getName(), Model::class)) {
                /** @var class-string<Model> $class */
                $class = $type->getName();

                return [
                    'class' => $class,
                    'field' => $route->bindingFieldFor($name) ?? (new $class)->getRouteKeyName(),
                    'selector' => false,
                ];
            }
        }

        return self::SCALAR_PARAMS[$name] ?? null;
    }

    /**
     * @param  array{key: string, method: string, route: Route, params: array<string, array{class: class-string<Model>, field: string, selector: bool}>}  $entry
     * @return array<string, array{tenant: 'a'|'b', records: 'a'|'b'}>
     */
    private function variantsFor(array $entry): array
    {
        $hasTenant = false;
        $hasRecords = false;

        foreach ($entry['params'] as $name => $param) {
            $isTenant = in_array($name, self::TENANT_PARAMS, true);
            $hasTenant = $hasTenant || $isTenant;
            $hasRecords = $hasRecords || (! $isTenant && ! $param['selector']);
        }

        $variants = [];

        if ($hasTenant) {
            $variants['foreign-team'] = ['tenant' => 'b', 'records' => 'b'];
        }

        if ($hasRecords) {
            $variants['foreign-records'] = ['tenant' => 'a', 'records' => 'b'];
        }

        $variants['own'] = ['tenant' => 'a', 'records' => 'a'];

        return $variants;
    }

    /**
     * @param  array{key: string, method: string, route: Route, params: array<string, array{class: class-string<Model>, field: string, selector: bool}>}  $entry
     * @param  array{tenant: 'a'|'b', records: 'a'|'b'}  $variant
     */
    private function probe(array $entry, string $variantName, array $variant): ?string
    {
        $values = [];
        $hasSelector = false;

        foreach ($entry['params'] as $name => $param) {
            $hasSelector = $hasSelector || $param['selector'];
            $side = match (true) {
                in_array($name, self::TENANT_PARAMS, true) => $variant['tenant'],
                $param['selector'] => 'b',
                default => $variant['records'],
            };

            $values[$name] = (string) $this->record($param['class'], $side)->getRawOriginal($param['field']);
        }

        $uri = '/'.ltrim((string) preg_replace_callback(
            '/\{(\w+)(?::\w+)?\??\}/',
            fn (array $m) => rawurlencode($values[$m[1]] ?? ''),
            $entry['route']->uri(),
        ), '/');

        $before = $this->teamBRowsDigest();
        $this->hydratedB = [];

        [$response, $rejectedByValidation] = $this->send($entry, $uri);

        $status = $response->getStatusCode();
        $served = $status >= 200 && $status < 400 && ! $rejectedByValidation;
        $changed = array_keys(array_diff_assoc($this->teamBRowsDigest(), $before));
        $isForeign = $variantName !== 'own';

        if ($changed !== []) {
            return "HTTP {$status} y modificó filas de B en: ".implode(', ', $changed);
        }

        if ($served && $this->hydratedB !== []) {
            return "HTTP {$status} habiendo cargado modelos de B: ".implode(', ', array_unique($this->hydratedB));
        }

        if ($served && ($leaked = $this->leakedFingerprint($response, $uri, $values)) !== null) {
            return "HTTP {$status} y la respuesta contiene un identificador de B ({$leaked})";
        }

        if ($isForeign && $status >= 200 && $status < 300) {
            return "HTTP {$status}: sirvió la petición con recursos de B (se esperaba 403/404/redirect)";
        }

        if ($isForeign && $status >= 500) {
            return "HTTP {$status}: error del servidor, no se puede afirmar el aislamiento";
        }

        if (! $isForeign && ! $hasSelector && $entry['method'] === 'GET' && in_array($status, [403, 404], true)) {
            return "HTTP {$status} con datos propios de A: el fixture no bindea y el barrido de esta ruta sería vacuo";
        }

        return null;
    }

    /**
     * @param  array{key: string, method: string, route: Route, params: array<string, array{class: class-string<Model>, field: string, selector: bool}>}  $entry
     * @return array{0: TestResponse, 1: bool} respuesta y si fue un rechazo de validación (redirect con errores)
     */
    private function send(array $entry, string $uri): array
    {
        $this->actingAs($this->userA)->withSession(['auth.password_confirmed_at' => time()]);

        $response = Str::startsWith($entry['route']->uri(), 'api/')
            ? $this->json($entry['method'], $uri)
            : $this->call($entry['method'], $uri);

        $rejectedByValidation = $response->isRedirect() && app('session.store')->has('errors');

        // Cada petición parte del team actual de A: EnsureTeamMembership no
        // debe dejarlo apuntando a otro sitio para la siguiente.
        $this->userA->refresh();
        TenantContext::set(null);

        return [$response, $rejectedByValidation];
    }

    /**
     * @param  class-string<Model>  $class
     * @param  'a'|'b'  $side
     */
    private function record(string $class, string $side): Model
    {
        if (! isset($this->records[$class])) {
            $this->records[$class] = $this->makeRecords($class);
        }

        return $this->records[$class][$side] ?? $this->records[$class]['b'];
    }

    /**
     * @param  class-string<Model>  $class
     * @return array{a?: Model, b: Model}
     */
    private function makeRecords(string $class): array
    {
        if (! Schema::hasColumn((new $class)->getTable(), 'team_id')) {
            // Catálogo de plataforma (sin team_id): no hay "registro de B";
            // ambas variantes usan la misma fila y lo que se barre es el team
            // de la URL y lo que la respuesta arrastra.
            $shared = $this->makeRecord($class, null);

            return ['a' => $shared, 'b' => $shared];
        }

        $b = $this->brandFingerprintTexts($this->makeRecord($class, $this->teamB));

        foreach (self::SCALAR_PARAMS as $scalar) {
            if ($scalar['class'] === $class && $scalar['selector']) {
                $this->collectFingerprints($b, null);

                return ['b' => $b];
            }
        }

        $a = $this->makeRecord($class, $this->teamA);
        $this->collectFingerprints($b, $a);

        return ['a' => $a, 'b' => $b];
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function makeRecord(string $class, ?Team $team): Model
    {
        return TenantContext::for($team?->id, function () use ($class, $team): Model {
            $this->assertTrue(method_exists($class, 'factory'), "{$class} necesita factory para el barrido cross-tenant.");

            /** @var Factory<Model> $factory */
            $factory = call_user_func([$class, 'factory']);
            $model = $factory->createOne($team === null ? [] : ['team_id' => $team->id, ...$this->fixtureAttributes($class, $team)]);

            if ($team !== null) {
                $this->afterFixture($model, $team);
            }

            return $model->fresh() ?? $model;
        });
    }

    /**
     * Atributos para que el registro de cada team sea servible por su propia
     * ruta (sin ellos el control `own` daría 403/404 y el barrido sería vacuo).
     *
     * @param  class-string<Model>  $class
     * @return array<string, mixed>
     */
    private function fixtureAttributes(string $class, Team $team): array
    {
        $owner = $team->is($this->teamA) ? $this->userA : $this->userB;

        return match ($class) {
            CopilotConversation::class => ['user_id' => $owner->id],
            CopilotMessage::class => [
                'copilot_conversation_id' => $this->record(CopilotConversation::class, $team->is($this->teamA) ? 'a' : 'b')->getKey(),
                'user_id' => null,
                // El feedback sólo existe sobre respuestas del asistente.
                'role' => CopilotMessageRole::Assistant,
            ],
            ReportExecution::class => ['file_path' => "reports/{$team->id}/sweep.csv"],
            default => [],
        };
    }

    private function afterFixture(Model $model, Team $team): void
    {
        if ($model instanceof ReportExecution && is_string($model->file_path)) {
            Storage::disk('rustfs')->put($model->file_path, "report,{$team->id}\n");
        }

        if ($model instanceof NormalizedEvent) {
            EventContextSnapshot::factory()->create(['normalized_event_id' => $model->id, 'team_id' => $team->id]);
        }
    }

    /**
     * Reescribe los textos distintivos de B con un marcador único (`B-…`).
     * El lorem de las factories («et consequatur») puede repetirse por azar
     * en un registro de A o en un catálogo global, y la respuesta de A lo
     * traería sin que haya fuga; con el marcador, la huella sólo puede
     * aparecer si la respuesta sirve de verdad el registro de B.
     */
    private function brandFingerprintTexts(Model $b): Model
    {
        $branded = [];

        foreach ($b->getAttributes() as $column => $value) {
            if (in_array($column, self::FINGERPRINT_TEXT_COLUMNS, true) && is_string($value) && mb_strlen($value) >= 8
                && ! Str::isUuid($value) && ! $b->hasCast($column)) {
                $branded[$column] = 'B-'.Str::lower(Str::random(12));
            }
        }

        if ($branded === []) {
            return $b;
        }

        return TenantContext::for($this->teamB->id, function () use ($b, $branded): Model {
            $b->forceFill($branded)->saveQuietly();

            return $b->fresh() ?? $b;
        });
    }

    /**
     * Identificadores de B que no deben aparecer en una respuesta servida a A:
     * los uuids y los textos distintivos del registro (los que no coinciden
     * con el registro equivalente de A).
     */
    private function collectFingerprints(Model $b, ?Model $a): void
    {
        $aValues = array_map(fn ($value) => is_scalar($value) ? (string) $value : null, $a?->getAttributes() ?? []);

        foreach ($b->getAttributes() as $column => $value) {
            if (! is_string($value) || in_array($value, $aValues, true)) {
                continue;
            }

            if (Str::isUuid($value) || (in_array($column, self::FINGERPRINT_TEXT_COLUMNS, true) && mb_strlen($value) >= 8)) {
                $this->fingerprintsB[] = $value;
            }
        }
    }

    /**
     * El slug y los uuids se buscan como subcadena (viajan en URLs y claves).
     * Un texto (nombre, título…) sólo cuenta si aparece como valor JSON
     * completo: el lorem de faker de B puede ser subcadena de un texto de A
     * («officia dolorum» dentro de una descripción) sin que haya fuga.
     */
    private function containsFingerprint(string $body, string $needle): bool
    {
        if ($needle === $this->teamB->slug || Str::isUuid($needle)) {
            return str_contains($body, $needle);
        }

        foreach ([JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 0] as $flags) {
            $quoted = (string) json_encode($needle, $flags);

            if (str_contains($body, $quoted) || str_contains($body, htmlspecialchars($quoted, ENT_QUOTES))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $urlValues
     */
    private function leakedFingerprint(TestResponse $response, string $uri, array $urlValues): ?string
    {
        $body = (string) $response->getContent();

        // Lo que la propia petición mandó (la URL y sus parámetros) puede
        // volver en la respuesta (`url` de la página Inertia, un trace id que
        // se devuelve tal cual): eso no es una fuga.
        $echoed = [$uri, str_replace('/', '\/', $uri), ...array_filter($urlValues, fn (string $value) => mb_strlen($value) >= 8)];
        $body = str_replace($echoed, '', $body);

        foreach ([$this->teamB->slug, $this->teamB->name, ...$this->fingerprintsB] as $needle) {
            if ($needle !== '' && $this->containsFingerprint($body, $needle)) {
                return $needle;
            }
        }

        if (preg_match('/"team_?[iI]d"\s*:\s*"?'.$this->teamB->id.'\b/', $body) === 1) {
            return "team_id {$this->teamB->id}";
        }

        return null;
    }

    private function listenForForeignHydration(): void
    {
        Event::listen('eloquent.retrieved: *', function (string $event, array $payload): void {
            $model = $payload[0] ?? null;

            if ($model instanceof Model && (int) $model->getAttribute('team_id') === $this->teamB->id) {
                $this->hydratedB[] = $model::class;
            }
        });
    }

    /**
     * Huella de todas las filas de B en cada tabla con `team_id`.
     *
     * @return array<string, string>
     */
    private function teamBRowsDigest(): array
    {
        $digest = [];

        foreach ($this->teamTables as $table) {
            $rows = DB::table($table)->where('team_id', $this->teamB->id)->get()->map(fn ($row) => json_encode((array) $row))->all();
            sort($rows);
            $digest[$table] = md5(implode("\n", $rows));
        }

        return $digest;
    }
}
