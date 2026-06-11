<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SAM platform notification channels (Roadmap V2-B1): the channels SAM
 * provides to every tenant (`team_id = null`). Credentials live here, in the
 * super-admin console — tenants only see masked summaries and an on/off
 * switch on their side.
 */
class GlobalChannelController extends Controller
{
    public function __construct(private readonly RecordAuditEntry $audit) {}

    public function index(): Response
    {
        $channels = NotificationChannel::query()
            ->whereNull('team_id')
            ->orderBy('channel_type')
            ->orderBy('name')
            ->get()
            ->map(fn (NotificationChannel $channel) => [
                'id' => (int) $channel->id,
                'code' => (string) $channel->code,
                'name' => (string) $channel->name,
                'provider' => (string) $channel->provider,
                'channelType' => $channel->channel_type?->value,
                'isActive' => (bool) $channel->is_active,
                'configKeys' => array_map('strval', array_keys((array) ($channel->config_json ?? []))),
            ])->values()->all();

        return Inertia::render('admin/channels/index', [
            'channels' => $channels,
            'channelTypes' => array_map(fn (ChannelType $type) => $type->value, ChannelType::cases()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:255', Rule::unique('notification_channels', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'provider' => ['required', 'string', 'max:64'],
            'channel_type' => ['required', Rule::enum(ChannelType::class)],
            'config_json' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $channel = NotificationChannel::query()->create([
            'team_id' => null,
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

        return redirect()->route('admin.channels.index')->with('status', 'Canal de plataforma creado.');
    }

    public function update(Request $request, NotificationChannel $channel): RedirectResponse
    {
        abort_unless($channel->team_id === null, 404);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'max:64'],
            'config_json' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $channel->update(array_filter($data, fn ($value) => $value !== null));

        $this->record($request, 'platform-channel.updated', $channel,
            "Canal de plataforma {$channel->code} actualizado.");

        return redirect()->route('admin.channels.index')->with('status', 'Canal actualizado.');
    }

    public function destroy(Request $request, NotificationChannel $channel): RedirectResponse
    {
        abort_unless($channel->team_id === null, 404);

        $this->record($request, 'platform-channel.deleted', $channel,
            "Canal de plataforma {$channel->code} eliminado.");

        $channel->delete();

        return redirect()->route('admin.channels.index')->with('status', 'Canal eliminado.');
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
