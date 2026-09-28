<?php

namespace Database\Seeders\Showcase;

use App\Domains\Incidents\Models\Incident;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Showcase\Support\ShowcaseRandom;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TODO lo de notificaciones vive en esta clase (el dominio se está
 * reestructurando en paralelo: estados de entrega de Twilio, cargos por
 * mensaje, canales sólo de plataforma). Extenderla aquí cuando eso entre.
 *
 * Qué siembra, SIN pasar por los drivers (nada sale del proceso):
 *  - Por incidente del showcase: aviso de creación, asignación de guardia,
 *    SLA vencido y cambio de estado, con destinatarios (usuarios y el
 *    contacto de emergencia del conductor) y una entrega por canal
 *    (web/email/sms/whatsapp/voz) con mezcla realista de entregadas,
 *    fallidas con el error del proveedor, omitidas y en cola.
 *  - Lecturas de los usuarios demo, preferencias por tipo, toggles del
 *    tenant sobre los canales de PLATAFORMA (nunca crea canales propios del
 *    tenant), tokens push y tokens de respuesta por SMS/WhatsApp.
 *
 * Marcador: `notifications.event_key = showcase:{team}:notif:{incidente}:{tipo}`.
 */
class NotificationsShowcaseSeeder extends ShowcaseStep
{
    /** @var array<string, int> tipo de canal => id del canal de plataforma */
    private array $channels = [];

    /** @var array<string, true> */
    private array $existingKeys = [];

    public function run(): void
    {
        $this->channels = NotificationChannel::query()
            ->whereIn('code', ['sam_email', 'sam_web', 'sam_sms', 'sam_whatsapp', 'sam_voice'])
            ->get()
            ->mapWithKeys(fn (NotificationChannel $c) => [$c->channel_type->value => (int) $c->id])
            ->all();

        $this->existingKeys = Notification::query()
            ->where('team_id', $this->ctx->team->id)
            ->where('event_key', 'like', 'showcase:%')
            ->pluck('event_key')
            ->mapWithKeys(fn (string $key) => [$key => true])
            ->all();

        $this->seedChannelToggles();
        $this->seedPreferences();
        $this->seedPushTokens();

        if ($this->channels !== []) {
            $this->seedIncidentNotifications();
        }
    }

    private function seedChannelToggles(): void
    {
        if (DB::table('tenant_channel_toggles')->where('team_id', $this->ctx->team->id)->exists()) {
            return;
        }

        $rows = [];

        foreach ($this->channels as $type => $id) {
            $rows[] = [
                'team_id' => $this->ctx->team->id,
                'notification_channel_id' => $id,
                // WhatsApp apagado: el tenant aún no aprueba su plantilla.
                'enabled' => $type !== 'whatsapp',
            ];
        }

        $this->bulkInsert('tenant_channel_toggles', $rows);
    }

    private function seedPreferences(): void
    {
        $rows = [];

        foreach ($this->ctx->users as $role => $user) {
            $exists = DB::table('notification_preferences')
                ->where('team_id', $this->ctx->team->id)
                ->where('user_id', $user->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $preferences = [
                'incident.created' => [['web', 'email', 'sms'], false],
                'incident.sla_breached' => [['web', 'whatsapp', 'sms'], false],
                'incident.assigned.on_call' => [['web', 'sms', 'voice'], false],
                'incident.status_changed' => [['web', 'email'], $role === 'viewer'],
            ];

            foreach ($preferences as $type => [$channels, $muted]) {
                $rows[] = [
                    'team_id' => $this->ctx->team->id,
                    'user_id' => $user->id,
                    'role' => $role,
                    'notification_type' => $type,
                    'allowed_channels_json' => $channels,
                    'muted' => $muted,
                    'quiet_hours_json' => $role === 'analyst' ? ['start' => '20:00', 'end' => '08:00'] : null,
                    'escalation_fallback_json' => null,
                ];
            }
        }

        $this->bulkInsert('notification_preferences', $rows);
    }

    private function seedPushTokens(): void
    {
        $rows = [];

        foreach (['admin', 'supervisor', 'monitor'] as $i => $role) {
            $user = $this->ctx->users[$role] ?? null;

            if ($user === null || DB::table('user_push_tokens')->where('user_id', $user->id)->where('team_id', $this->ctx->team->id)->exists()) {
                continue;
            }

            $rows[] = [
                'user_id' => $user->id,
                'team_id' => $this->ctx->team->id,
                'platform' => $i === 1 ? 'android' : 'ios',
                'token' => 'showcase-'.hash('sha256', "push-{$this->ctx->team->id}-{$user->id}"),
                'device_name' => $i === 1 ? 'Galaxy A54 de '.Str::before($user->name, ' ') : 'iPhone de '.Str::before($user->name, ' '),
                'last_used_at' => $this->ctx->now->subHours($i + 1),
            ];
        }

        $this->bulkInsert('user_push_tokens', $rows);
    }

    private function seedIncidentNotifications(): void
    {
        $incidents = Incident::query()
            ->where('team_id', $this->ctx->team->id)
            ->whereNotNull('metadata_json->showcase_key')
            ->with(['priority', 'status', 'type', 'driver'])
            ->orderBy('opened_at')
            ->get();

        foreach ($incidents as $incident) {
            $random = ShowcaseRandom::forKey('notifications:'.$incident->id);
            $opened = CarbonImmutable::parse($incident->opened_at);
            $priority = match ($incident->priority?->code) {
                'critical' => 'critical',
                'high' => 'high',
                'low' => 'low',
                default => 'normal',
            };
            $operator = User::query()->find($incident->claimed_by_user_id ?? $incident->acknowledged_by) ?? $this->ctx->user('monitor');
            $supervisor = $this->ctx->users['supervisor'] ?? $this->ctx->user('admin');
            $isPanic = $incident->type?->code === 'panic_emergency';

            $channels = match ($priority) {
                'critical' => ['web', 'sms', 'voice', 'whatsapp', 'email'],
                'high' => ['web', 'sms', 'email'],
                default => ['web', 'email'],
            };

            $this->notify($incident, $isPanic ? 'incident.panic_emergency.created' : 'incident.created', 'created', $priority, $opened->addSeconds(3),
                ($isPanic ? '🚨 Pánico: ' : 'Nuevo incidente: ').$incident->title,
                (string) Str::limit((string) $incident->summary, 180),
                [$operator, $supervisor], $channels, $random, withDriverContact: $isPanic && $incident->driver_id !== null);

            $this->notify($incident, 'incident.assigned.on_call', 'assigned', $priority, $opened->addSeconds(40),
                'Te asignaron: '.$incident->title,
                'Eres el monitorista de guardia para este incidente.',
                [$operator], ['web', 'sms'], $random);

            if ($incident->sla_due_at !== null && ($incident->resolved_at === null || $incident->resolved_at->greaterThan($incident->sla_due_at)) && $incident->sla_due_at->lessThan($this->ctx->now)) {
                $this->notify($incident, 'incident.sla_breached', 'sla', $priority === 'low' ? 'normal' : 'critical', CarbonImmutable::parse($incident->sla_due_at),
                    'SLA vencido: '.$incident->title,
                    'El incidente superó el tiempo de atención comprometido.',
                    [$supervisor], ['web', 'whatsapp', 'sms'], $random);
            }

            if ($incident->resolved_at !== null) {
                $this->notify($incident, 'incident.status_changed', 'resolved', 'low', CarbonImmutable::parse($incident->resolved_at)->addSeconds(5),
                    'Incidente '.($incident->status?->code === 'false_positive' ? 'descartado' : 'resuelto').': '.$incident->title,
                    (string) ($incident->resolution?->resolution_summary ?? 'Caso cerrado por el monitorista.'),
                    [$supervisor], ['web', 'email'], $random);
            }
        }
    }

    /**
     * @param  array<int, User>  $users
     * @param  array<int, string>  $channelTypes
     */
    private function notify(Incident $incident, string $type, string $kind, string $priority, CarbonImmutable $at, string $subject, string $preview, array $users, array $channelTypes, ShowcaseRandom $random, bool $withDriverContact = false): void
    {
        $key = sprintf('showcase:%d:notif:%d:%s', $this->ctx->team->id, $incident->id, $kind);

        if (isset($this->existingKeys[$key]) || $at->greaterThan($this->ctx->now)) {
            return;
        }

        $users = array_values(array_unique(array_filter($users), SORT_REGULAR));
        $ageMinutes = $at->diffInMinutes($this->ctx->now);
        $inFlight = $ageMinutes < 2;

        // Unos pocos avisos nocturnos de baja prioridad se cancelan sin
        // contactos: la pantalla explica el motivo ("sin contactos").
        $noContacts = $priority === 'low' && $random->chance(0.05);

        $notification = Notification::query()->create([
            'team_id' => $this->ctx->team->id,
            'source_type' => 'incident',
            'source_reference_id' => (string) $incident->id,
            'notification_type' => $type,
            'priority' => $priority,
            'status' => 'pending',
            'subject' => $subject,
            'body_preview' => $preview,
            'triggered_by_type' => $kind === 'assigned' ? 'automation' : 'system',
            'event_key' => $key,
            'payload_json' => ['incident_id' => $incident->id, 'incident_title' => $incident->title, 'showcase' => true],
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $this->existingKeys[$key] = true;
        $this->ctx->count('notifications');

        $statuses = [];

        if (! $noContacts) {
            foreach ($users as $user) {
                $recipient = $this->recipient($notification->id, 'user', (string) $user->id, $user->name, $user->email, $user->phone, $at);

                foreach ($channelTypes as $channel) {
                    $statuses[] = $this->delivery($incident->id, $notification->id, $recipient, $channel, $at, $inFlight, $random, $user->phone);
                }

                if (in_array('web', $channelTypes, true) && ! $inFlight && $ageMinutes > 30 && $random->chance(0.75)) {
                    DB::table('notification_reads')->insertOrIgnore([
                        'team_id' => $this->ctx->team->id,
                        'notification_id' => $notification->id,
                        'user_id' => $user->id,
                        'read_at' => $at->addMinutes($random->int(1, 30)),
                        'created_at' => $at,
                        'updated_at' => $at,
                    ]);
                    $this->ctx->count('notification_reads');
                }
            }

            if ($withDriverContact) {
                $contact = DB::table('driver_contacts')->where('driver_id', $incident->driver_id)->where('is_emergency', true)->first();

                if ($contact !== null) {
                    $recipient = $this->recipient($notification->id, 'external_contact', 'driver_contact:'.$contact->id, 'Contacto de emergencia ('.$contact->label.')', null, $contact->value, $at);
                    $statuses[] = $this->delivery($incident->id, $notification->id, $recipient, 'sms', $at, $inFlight, $random, $contact->value);
                }
            }
        }

        $status = match (true) {
            $noContacts => 'cancelled',
            $inFlight => 'queued',
            $statuses === [] => 'cancelled',
            ! in_array('delivered', $statuses, true) && ! in_array('queued', $statuses, true) => in_array('failed', $statuses, true) || in_array('bounced', $statuses, true) ? 'failed' : 'cancelled',
            count(array_intersect($statuses, ['failed', 'bounced', 'skipped'])) > 0 => 'partially_sent',
            default => 'sent',
        };

        $notification->forceFill([
            'status' => $status,
            'sent_at' => in_array($status, ['sent', 'partially_sent'], true) ? $at->addSeconds(2) : null,
        ])->save();
    }

    private function recipient(int $notificationId, string $type, string $reference, string $name, ?string $email, ?string $phone, CarbonImmutable $at): int
    {
        $id = DB::table('notification_recipients')->insertGetId([
            'notification_id' => $notificationId,
            'team_id' => $this->ctx->team->id,
            'recipient_type' => $type,
            'recipient_reference_id' => $reference,
            'name' => $name,
            'address' => $email ?? $phone ?? 'sin-contacto',
            'email' => $email,
            'phone' => $phone,
            'channel_preference' => null,
            'role' => $type === 'user' ? 'operator' : 'emergency_contact',
            'metadata_json' => json_encode(['showcase' => true]),
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $this->ctx->count('notification_recipients');

        return $id;
    }

    private function delivery(int $incidentId, int $notificationId, int $recipientId, string $channel, CarbonImmutable $at, bool $inFlight, ShowcaseRandom $random, ?string $phone): string
    {
        $channelId = $this->channels[$channel] ?? null;

        if ($channelId === null) {
            return 'skipped';
        }

        [$status, $error] = match (true) {
            $inFlight => ['queued', null],
            in_array($channel, ['sms', 'voice', 'whatsapp'], true) && $phone === null => ['skipped', 'El destinatario no tiene teléfono verificado.'],
            $channel === 'web' => ['delivered', null],
            $channel === 'email' => $random->chance(0.03) ? ['bounced', 'SMTP 550 5.1.1: el buzón no existe.'] : ['delivered', null],
            $channel === 'sms' => $random->chance(0.07) ? ['failed', $random->pick(['Twilio 30003: el teléfono destino no está disponible.', 'Twilio 30006: el número es fijo o no puede recibir SMS.'])] : ['delivered', null],
            $channel === 'whatsapp' => $random->chance(0.35) ? ['skipped', 'Canal WhatsApp desactivado para el tenant (plantilla pendiente de aprobación).'] : ($random->chance(0.1) ? ['failed', 'Twilio 63016: fuera de la ventana de 24 h, se requiere plantilla.'] : ['delivered', null]),
            $channel === 'voice' => $random->chance(0.18) ? ['failed', 'Llamada sin respuesta tras 30 s (no-answer).'] : ['delivered', null],
            default => ['delivered', null],
        };

        $sent = $at->addSeconds($random->int(1, 6));

        DB::table('notification_deliveries')->insert([
            'notification_id' => $notificationId,
            'recipient_id' => $recipientId,
            'channel_id' => $channelId,
            'team_id' => $this->ctx->team->id,
            'provider_message_id' => in_array($status, ['delivered', 'failed'], true) && $channel !== 'web'
                ? ($channel === 'email' ? '<showcase-'.$notificationId.'-'.$recipientId.'@mail.sam.test>' : ($channel === 'voice' ? 'CA' : 'SM').md5("showcase-{$notificationId}-{$recipientId}-{$channel}"))
                : null,
            'status' => $status,
            'attempt_number' => $status === 'failed' ? 3 : 1,
            'payload_json' => json_encode(['channel' => $channel, 'showcase' => true]),
            'response_json' => $error !== null ? json_encode(['error' => $error]) : null,
            'error_message' => $error,
            'sent_at' => in_array($status, ['delivered', 'failed', 'bounced'], true) ? $sent : null,
            'delivered_at' => $status === 'delivered' ? $sent->addSeconds($random->int(1, 20)) : null,
            'failed_at' => in_array($status, ['failed', 'bounced'], true) ? $sent->addSeconds($random->int(5, 90)) : null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $this->ctx->count('notification_deliveries');

        if (in_array($channel, ['sms', 'whatsapp'], true) && $status === 'delivered' && $random->chance(0.4)) {
            $consumed = $random->chance(0.6);
            DB::table('notification_reply_tokens')->insert([
                'team_id' => $this->ctx->team->id,
                'incident_id' => $incidentId,
                'notification_id' => $notificationId,
                'user_id' => null,
                'channel_type' => $channel,
                'address' => (string) $phone,
                'token' => substr(hash('sha256', "reply-{$notificationId}-{$recipientId}-{$channel}"), 0, 16),
                'expires_at' => $at->addMinutes(30),
                'consumed_at' => $consumed ? $sent->addMinutes($random->int(1, 9)) : null,
                'consumed_action' => $consumed ? 'acknowledge' : null,
                'reply_payload_json' => $consumed ? json_encode(['Body' => '1']) : null,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            $this->ctx->count('notification_reply_tokens');
        }

        return $status;
    }
}
