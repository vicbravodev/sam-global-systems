<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Support\PlatformTwilioConfig;
use App\Http\Controllers\Controller;
use App\Rules\SafeOutboundUrl;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SAM platform notification channels (Roadmap V2-B1): the channels SAM
 * provides to every tenant. Tenants only get an on/off switch on their side.
 *
 * Twilio credentials never live here — they are platform env (TWILIO_*). A
 * Twilio channel's `config_json` only accepts non-secret overrides
 * ({@see PlatformTwilioConfig::ALLOWED_CHANNEL_KEYS}), and Twilio-style
 * account credentials are rejected for every provider.
 */
class GlobalChannelController extends Controller
{
    /**
     * Twilio account credentials: platform env only, never a channel row.
     *
     * @var list<string>
     */
    private const CREDENTIAL_KEYS = ['account_sid', 'auth_token'];

    public function __construct(private readonly RecordAuditEntry $audit) {}

    public function index(): Response
    {
        $channels = NotificationChannel::query()
            ->orderBy('channel_type')
            ->orderBy('name')
            ->get()
            ->map(fn (NotificationChannel $channel) => [
                'id' => $channel->id,
                'code' => $channel->code,
                'name' => $channel->name,
                'provider' => $channel->provider,
                'channelType' => $channel->channel_type?->value,
                'isActive' => $channel->is_active,
                'configKeys' => array_map('strval', array_keys($channel->config_json ?? [])),
            ])->values()->all();

        return Inertia::render('admin/channels/index', [
            'channels' => $channels,
            'channelTypes' => array_map(
                fn (ChannelType $type) => ['value' => $type->value, 'label' => $type->label()],
                ChannelType::cases(),
            ),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate($this->outboundUrlRules());

        $data = $request->validate([
            'code' => ['required', 'string', 'max:255', Rule::unique('notification_channels', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'provider' => ['required', 'string', 'max:64'],
            'channel_type' => ['required', Rule::enum(ChannelType::class)],
            'config_json' => ['nullable', 'array', $this->configRule((string) $request->input('provider'))],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $channel = NotificationChannel::query()->create([
            'code' => $data['code'],
            'name' => $data['name'],
            'provider' => $data['provider'],
            'channel_type' => ChannelType::from($data['channel_type']),
            'config_json' => $data['config_json'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'supports_priority' => false,
            'supports_template' => true,
        ]);

        $this->record($request, 'platform-channel.created', $channel,
            "Canal de plataforma {$channel->code} ({$channel->channel_type->value}) creado.");

        $this->toast('Canal de plataforma creado.');

        return redirect()->route('admin.channels.index');
    }

    public function update(Request $request, NotificationChannel $channel): RedirectResponse
    {
        $request->validate($this->outboundUrlRules());

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'max:64'],
            'config_json' => ['nullable', 'array', $this->configRule((string) $request->input('provider', $channel->provider))],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $channel->update(array_filter($data, fn ($value) => $value !== null));

        $this->record($request, 'platform-channel.updated', $channel,
            "Canal de plataforma {$channel->code} actualizado.");

        $this->toast('Canal actualizado.');

        return redirect()->route('admin.channels.index');
    }

    public function destroy(Request $request, NotificationChannel $channel): RedirectResponse
    {
        $this->record($request, 'platform-channel.deleted', $channel,
            "Canal de plataforma {$channel->code} eliminado.");

        $channel->delete();

        $this->toast('Canal eliminado.');

        return redirect()->route('admin.channels.index');
    }

    /**
     * URLs a las que los drivers harán POST (Slack, webhook saliente): deben
     * pasar OutboundUrlGuard (https, sin red interna). Se valida al guardar
     * para avisar pronto; el driver lo vuelve a comprobar al enviar. Se
     * validan aparte: como reglas anidadas recortarían `config_json` en los
     * datos validados.
     *
     * @return array<string, list<mixed>>
     */
    private function outboundUrlRules(): array
    {
        return [
            'config_json.slack_webhook_url' => ['nullable', 'string', 'max:2048', new SafeOutboundUrl],
            'config_json.endpoint_url' => ['nullable', 'string', 'max:2048', new SafeOutboundUrl],
        ];
    }

    /**
     * Twilio channels accept only the non-secret overrides; no channel of any
     * provider may carry Twilio/account credentials in its config.
     */
    private function configRule(string $provider): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($provider): void {
            if (! is_array($value)) {
                return;
            }

            $keys = array_map('strval', array_keys($value));

            $forbidden = array_values(array_filter(
                $keys,
                fn (string $key) => in_array($key, self::CREDENTIAL_KEYS, true) || str_starts_with($key, 'twilio_'),
            ));

            if ($provider === 'twilio') {
                $forbidden = array_values(array_unique([
                    ...$forbidden,
                    ...array_diff($keys, PlatformTwilioConfig::ALLOWED_CHANNEL_KEYS),
                ]));
            }

            if ($forbidden !== []) {
                $fail('La configuración no puede incluir credenciales ni llaves no permitidas: '.implode(', ', $forbidden).'. Las credenciales de Twilio se configuran en el entorno de la plataforma (TWILIO_*).');
            }
        };
    }

    private function record(Request $request, string $action, NotificationChannel $channel, string $summary): void
    {
        $this->audit->execute(
            actorType: AuditActorType::User,
            actorId: $request->user()?->id,
            action: $action,
            category: AuditCategory::Security,
            entityType: 'notification_channel',
            entityId: $channel->id,
            summary: $summary,
            teamId: null,
            metadata: ['channel_type' => $channel->channel_type?->value],
            sourceType: 'admin_console',
            sourceReferenceId: (string) $channel->id,
        );
    }
}
