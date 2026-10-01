<?php

namespace Database\Seeders\Showcase;

use App\Domains\Incidents\Models\Incident;
use App\Domains\Notifications\Actions\ApplyTwilioStatusUpdate;
use App\Domains\Notifications\Actions\FinalizeMessagingCharge;
use App\Domains\Notifications\Actions\RefreshNotificationStatus;
use App\Domains\Notifications\Enums\MessagingChargeSource;
use App\Domains\Notifications\Enums\MessagingResourceType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Support\TwilioErrorCatalog;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Showcase\Support\ShowcaseRandom;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TODO lo de notificaciones vive en esta clase, SIN pasar por los drivers
 * (nada sale del proceso):
 *
 *  - Por incidente del showcase: aviso de creación, asignación de guardia,
 *    SLA vencido y cambio de estado, con destinatarios (usuarios y el
 *    contacto de emergencia del conductor) y una entrega por canal.
 *  - Web/email: entregadas, rebotadas u omitidas (driver síncrono).
 *  - SMS/WhatsApp/voz con el ciclo real de Twilio: `provider_status`,
 *    `accepted_at` → `sent_at` → `delivered_at` / `read_at` (WhatsApp) /
 *    `answered_at` + `call_duration_seconds` (voz), `segments`; fallos con
 *    códigos de {@see TwilioErrorCatalog} (30003 transitorio → reintento con
 *    SID nuevo; 21211/21610/63016… permanentes → fallback a otro canal),
 *    envíos de los últimos minutos aún `queued`/`sent`.
 *  - Una fila de `messaging_charges` por SID (entregas, llamadas de
 *    verificación y OTP) con `events_json`, precio real (SMS 7 900 µUSD por
 *    segmento, WhatsApp 5 000, voz 14 000 por minuto; lo no cobrado en 0;
 *    algunos estimados) y el uso `messaging_cost_micros` con la misma clave
 *    que el código real (`twilio_charge:{sid}`): de ahí sale la línea
 *    cost-plus "Mensajería y llamadas (Twilio)" de la factura.
 *  - Lecturas, preferencias, toggles del tenant sobre los canales de
 *    PLATAFORMA (nunca crea canales propios), tokens push y de respuesta.
 *
 * Marcadores: `notifications.event_key = showcase:{team}:notif:{incidente}:{tipo}`
 * y `messaging_charges.provider_sid` determinista (`insertOrIgnore`).
 */
class NotificationsShowcaseSeeder extends ShowcaseStep
{
    /** @var array<string, int> tipo de canal => id del canal de plataforma */
    private array $channels = [];

    /** @var array<string, bool> */
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
        $this->dropChannelsSwitchedOff();
        $this->seedPreferences();
        $this->seedPushTokens();

        if ($this->channels !== []) {
            $this->seedIncidentNotifications();
        }

        $this->seedVerificationCallCharges();
        $this->seedOtpCharges();
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

    /**
     * The dispatcher only selects channels the tenant can use
     * (`NotificationChannel::usableByTeam`): a channel switched off in
     * `tenant_channel_toggles` never gets a delivery row, not even a skipped
     * one, and is never a fallback target. Mirror that so the showcase never
     * shows WhatsApp deliveries on a tenant that has WhatsApp off.
     */
    private function dropChannelsSwitchedOff(): void
    {
        $disabled = DB::table('tenant_channel_toggles')
            ->where('team_id', $this->ctx->team->id)
            ->where('enabled', false)
            ->pluck('notification_channel_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->channels = array_filter(
            $this->channels,
            fn (int $channelId) => ! in_array($channelId, $disabled, true),
        );
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
            ->with(['priority', 'status', 'type', 'driver', 'resolution'])
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

        $users = array_values(array_unique($users, SORT_REGULAR));
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

        /** @var array<int, array<int, string>> recipient_id => estados de sus entregas intentadas */
        $byRecipient = [];

        if (! $noContacts) {
            foreach ($users as $user) {
                $recipient = $this->recipient($notification->id, 'user', (string) $user->id, $user->name, $user->email, $user->phone, $at);
                $byRecipient[$recipient] = $this->deliverToRecipient($incident->id, $notification->id, $recipient, $channelTypes, $at, $random, $user->email, $user->phone);

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
                    $byRecipient[$recipient] = $this->deliverToRecipient($incident->id, $notification->id, $recipient, ['sms'], $at, $random, null, $contact->value);
                }
            }
        }

        $status = $this->aggregateStatus($byRecipient);

        $notification->forceFill([
            'status' => $status,
            'sent_at' => in_array($status, ['sent', 'partially_sent'], true) ? $at->addSeconds(2) : null,
        ])->save();
    }

    /**
     * Misma regla que {@see RefreshNotificationStatus}: por destinatario, un
     * fallback que llegó compensa al canal primario que falló.
     *
     * @param  array<int, array<int, string>>  $byRecipient
     */
    private function aggregateStatus(array $byRecipient): string
    {
        $reached = $failed = $pending = 0;

        foreach ($byRecipient as $statuses) {
            $attempted = array_values(array_diff($statuses, ['skipped', 'cancelled']));

            if ($attempted === []) {
                continue;
            }

            if (array_intersect($attempted, ['queued', 'sent', 'delivered']) !== []) {
                $reached++;
            } elseif (array_diff($attempted, ['failed', 'bounced']) === []) {
                $failed++;
            } else {
                $pending++;
            }
        }

        return match (true) {
            $reached + $failed + $pending === 0 => 'cancelled',
            $reached > 0 && $failed === 0 => 'sent',
            $reached > 0 => 'partially_sent',
            $pending > 0 => 'queued',
            default => 'failed',
        };
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

    /**
     * Entrega a un destinatario por cada canal y, tras un fallo PERMANENTE de
     * Twilio, por el canal de fallback (WhatsApp → SMS, SMS/voz → email),
     * como hace RetryOrFallbackOnNotificationFailed.
     *
     * @param  array<int, string>  $channels
     * @return array<int, string> estados finales de sus entregas
     */
    private function deliverToRecipient(int $incidentId, int $notificationId, int $recipientId, array $channels, CarbonImmutable $at, ShowcaseRandom $random, ?string $email, ?string $phone): array
    {
        $statuses = [];
        $fallbacks = [];
        // A switched-off channel is never selected by the dispatcher.
        $channels = array_values(array_filter($channels, fn (string $channel) => isset($this->channels[$channel])));

        foreach ($channels as $channel) {
            [$status, $fallback, $deliveryId] = $this->delivery($incidentId, $notificationId, $recipientId, $channel, $at, $random, $email, $phone);
            $statuses[] = $status;

            if ($fallback !== null && ! isset($fallbacks[$fallback])) {
                $fallbacks[$fallback] = $deliveryId;
            }
        }

        foreach ($fallbacks as $fallback => $failedDeliveryId) {
            // Una entrega por (destinatario, canal): si ya se usó, el fallback prueba email.
            $target = in_array($fallback, $channels, true) ? 'email' : $fallback;

            if (in_array($target, $channels, true) || ! isset($this->channels[$target]) || ($target === 'email' && $email === null)) {
                continue;
            }

            $channels[] = $target;
            [$status] = $this->delivery($incidentId, $notificationId, $recipientId, $target, $at->addMinutes(2), $random, $email, $phone, fallbackFromDeliveryId: $failedDeliveryId);
            $statuses[] = $status;
        }

        return $statuses;
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?int} [estado final, canal de fallback si falló permanente, id de la entrega]
     */
    private function delivery(int $incidentId, int $notificationId, int $recipientId, string $channel, CarbonImmutable $at, ShowcaseRandom $random, ?string $email, ?string $phone, ?int $fallbackFromDeliveryId = null): array
    {
        $channelId = $this->channels[$channel] ?? null;
        $isFallback = $fallbackFromDeliveryId !== null;

        if ($channelId === null) {
            return ['skipped', null, null];
        }

        $twilio = in_array($channel, ['sms', 'whatsapp', 'voice'], true);
        $base = [
            'notification_id' => $notificationId,
            'recipient_id' => $recipientId,
            'channel_id' => $channelId,
            'fallback_from_delivery_id' => $fallbackFromDeliveryId,
            'team_id' => $this->ctx->team->id,
            'payload_json' => json_encode(['channel' => $channel, 'address' => $channel === 'email' ? $email : ($channel === 'web' ? null : $phone), 'fallback' => $isFallback, 'showcase' => true]),
            'created_at' => $at,
            'updated_at' => $at,
        ];

        if (! $twilio) {
            [$status, $deliveryId] = $this->simpleDelivery($base, $channel, $at, $random, $notificationId, $recipientId, $email);

            return [$status, null, $deliveryId];
        }

        if ($phone === null) {
            $deliveryId = DB::table('notification_deliveries')->insertGetId($base + ['status' => 'skipped', 'attempt_number' => 1, 'error_message' => 'El destinatario no tiene teléfono verificado.']);
            $this->ctx->count('notification_deliveries');

            return ['skipped', null, $deliveryId];
        }

        $plan = $this->twilioPlan($channel, $at, $random);
        $deliveryId = DB::table('notification_deliveries')->insertGetId($base + ['status' => 'pending', 'attempt_number' => 1]);
        $this->ctx->count('notification_deliveries');

        $final = null;
        $firstAccepted = null;

        foreach ($plan['attempts'] as $n => $attempt) {
            $sid = ($channel === 'voice' ? 'CA' : 'SM').md5("showcase-sid-{$deliveryId}-{$n}");
            $start = $at->addSeconds(2 + $n * 95);
            $timeline = $this->providerTimeline($channel, $attempt, $start, $random);
            $firstAccepted ??= $timeline['accepted_at'];
            $this->charge($sid, $channel, MessagingChargeSource::NotificationDelivery, $deliveryId, $attempt, $timeline, $random);
            $final = ['sid' => $sid, 'attempt' => $attempt, 'timeline' => $timeline, 'number' => $n + 1];
        }

        $attempt = $final['attempt'];
        $t = $final['timeline'];
        $status = $attempt['delivery_status'];

        DB::table('notification_deliveries')->where('id', $deliveryId)->update([
            'provider_message_id' => $final['sid'],
            'status' => $status,
            'attempt_number' => $final['number'],
            'provider_status' => $attempt['provider_status'],
            'provider_error_code' => $attempt['error_code'],
            'permanent_failure' => TwilioErrorCatalog::isPermanent($attempt['error_code']),
            'accepted_at' => $firstAccepted,
            'sent_at' => $t['sent_at'],
            'delivered_at' => $t['delivered_at'],
            'read_at' => $t['read_at'],
            'answered_at' => $t['answered_at'],
            'call_duration_seconds' => $attempt['duration'],
            'segments' => $attempt['segments'],
            'failed_at' => $status === 'failed' ? $t['last_at'] : null,
            'last_provider_event_at' => $t['last_at'],
            'error_message' => $status === 'failed'
                ? sprintf('twilio %s %s', $channel === 'voice' ? 'call' : 'message', $attempt['provider_status']).($attempt['error_code'] ? " (error {$attempt['error_code']})" : '')
                : null,
            'response_json' => json_encode(['sid' => $final['sid'], 'status' => $attempt['provider_status']]),
        ]);

        if (in_array($channel, ['sms', 'whatsapp'], true) && $status === 'delivered' && $random->chance(0.4)) {
            $this->replyToken($incidentId, $notificationId, $recipientId, $channel, (string) $phone, $at, $t['delivered_at'] ?? $at, $random);
        }

        $fallback = $status === 'failed' && (TwilioErrorCatalog::isPermanent($attempt['error_code']) || $channel === 'voice')
            ? ($channel === 'whatsapp' ? 'sms' : ($channel === 'voice' ? 'sms' : 'email'))
            : null;

        return [$status, $isFallback ? null : $fallback, $deliveryId];
    }

    /**
     * Web y email: el driver es síncrono (éxito = entregado).
     *
     * @param  array<string, mixed>  $base
     * @return array{0: string, 1: int} [estado, id de la entrega]
     */
    private function simpleDelivery(array $base, string $channel, CarbonImmutable $at, ShowcaseRandom $random, int $notificationId, int $recipientId, ?string $email): array
    {
        $inFlight = $at->diffInMinutes($this->ctx->now) < 2;
        [$status, $error] = match (true) {
            $channel === 'email' && $email === null => ['skipped', 'El destinatario no tiene correo.'],
            $inFlight => ['queued', null],
            $channel === 'email' && $random->chance(0.03) => ['bounced', 'SMTP 550 5.1.1: el buzón no existe.'],
            default => ['delivered', null],
        };
        $sent = $at->addSeconds($random->int(1, 6));

        $deliveryId = DB::table('notification_deliveries')->insertGetId($base + [
            'provider_message_id' => $channel === 'email' && in_array($status, ['delivered', 'bounced'], true) ? '<showcase-'.$notificationId.'-'.$recipientId.'@mail.sam.test>' : null,
            'status' => $status,
            'attempt_number' => 1,
            'response_json' => $error !== null ? json_encode(['error' => $error]) : null,
            'error_message' => $error,
            'sent_at' => in_array($status, ['delivered', 'bounced'], true) ? $sent : null,
            'delivered_at' => $status === 'delivered' ? $sent->addSeconds($random->int(1, 20)) : null,
            'failed_at' => $status === 'bounced' ? $sent->addSeconds($random->int(5, 90)) : null,
        ]);
        $this->ctx->count('notification_deliveries');

        return [$status, $deliveryId];
    }

    /**
     * Ciclo de vida del envío en Twilio: intentos (con reintento tras un
     * fallo transitorio) y cómo termina cada uno.
     *
     * @return array{attempts: non-empty-list<array{provider_status: string, delivery_status: string, error_code: ?string, segments: ?int, duration: ?int, read: bool}>}
     */
    private function twilioPlan(string $channel, CarbonImmutable $at, ShowcaseRandom $random): array
    {
        $age = $at->diffInMinutes($this->ctx->now);
        $segments = $channel === 'sms' ? ($random->chance(0.8) ? 1 : 2) : null;
        $ok = fn (): array => match ($channel) {
            'voice' => ['provider_status' => 'completed', 'delivery_status' => 'delivered', 'error_code' => null, 'segments' => null, 'duration' => $random->int(18, 95), 'read' => false],
            'whatsapp' => ['provider_status' => ($read = $random->chance(0.65)) ? 'read' : 'delivered', 'delivery_status' => 'delivered', 'error_code' => null, 'segments' => 1, 'duration' => null, 'read' => $read],
            default => ['provider_status' => 'delivered', 'delivery_status' => 'delivered', 'error_code' => null, 'segments' => $segments, 'duration' => null, 'read' => false],
        };
        $fail = fn (string $providerStatus, ?string $code): array => ['provider_status' => $providerStatus, 'delivery_status' => 'failed', 'error_code' => $code, 'segments' => $segments, 'duration' => $channel === 'voice' ? 0 : null, 'read' => false];

        // Envíos de los últimos minutos: aún en la cola de Twilio, sin estado final.
        if ($age < 3 || ($age < 10 && $random->chance(0.4))) {
            return ['attempts' => [['provider_status' => 'queued', 'delivery_status' => 'queued', 'error_code' => null, 'segments' => $segments, 'duration' => null, 'read' => false]]];
        }

        if ($age < 15 && $channel !== 'voice' && $random->chance(0.5)) {
            return ['attempts' => [['provider_status' => 'sent', 'delivery_status' => 'sent', 'error_code' => null, 'segments' => $segments, 'duration' => null, 'read' => false]]];
        }

        $roll = $random->float(0, 1, 4);

        return ['attempts' => match ($channel) {
            'sms' => match (true) {
                $roll < 0.03 => [$fail('undelivered', '21211')],
                $roll < 0.05 => [$fail('undelivered', '21610')],
                $roll < 0.07 => [$fail('undelivered', '30006')],
                $roll < 0.13 => [$fail('undelivered', '30003'), $random->chance(0.8) ? $ok() : $fail('undelivered', '30003')],
                default => [$ok()],
            },
            'whatsapp' => match (true) {
                $roll < 0.08 => [$fail('failed', '63016')],
                $roll < 0.11 => [$fail('undelivered', '63024')],
                $roll < 0.16 => [$fail('undelivered', '30003'), $ok()],
                default => [$ok()],
            },
            default => match (true) {
                $roll < 0.12 => [$fail('no-answer', null), $random->chance(0.7) ? $ok() : $fail('no-answer', null)],
                $roll < 0.15 => [$fail('busy', null), $ok()],
                $roll < 0.17 => [$fail('failed', '21214')],
                default => [$ok()],
            },
        }];
    }

    /**
     * Marcas de tiempo y eventos de Twilio de un intento.
     *
     * @param  array{provider_status: string, delivery_status?: string, error_code: ?string, segments?: ?int, duration: ?int, read?: bool}  $attempt
     * @return array{accepted_at: CarbonImmutable, sent_at: ?CarbonImmutable, delivered_at: ?CarbonImmutable, read_at: ?CarbonImmutable, answered_at: ?CarbonImmutable, last_at: CarbonImmutable, events: array<int, array<string, mixed>>}
     */
    private function providerTimeline(string $channel, array $attempt, CarbonImmutable $start, ShowcaseRandom $random): array
    {
        $cursor = $start;
        $events = [['status' => 'queued', 'error_code' => null, 'at' => $cursor->toIso8601String(), 'source' => 'api']];
        $sentAt = $deliveredAt = $readAt = $answeredAt = null;
        $final = $attempt['provider_status'];
        $push = function (string $status, int $seconds, ?string $code = null, string $source = 'callback') use (&$cursor, &$events): CarbonImmutable {
            $cursor = $cursor->addSeconds($seconds);
            $events[] = ['status' => $status, 'error_code' => $code, 'at' => $cursor->toIso8601String(), 'source' => $source];

            return $cursor;
        };

        if ($channel === 'voice') {
            if ($final !== 'queued') {
                $sentAt = $push('initiated', 1);
                $push('ringing', $random->int(1, 4));

                if ($final === 'completed') {
                    $answeredAt = $deliveredAt = $push('in-progress', $random->int(4, 20));
                    $push('completed', (int) $attempt['duration']);
                } else {
                    $push($final, $final === 'busy' ? 3 : 30, $attempt['error_code']);
                }
            }
        } elseif ($final !== 'queued') {
            $sentAt = $push('sent', $random->int(1, 4));

            if (in_array($final, ['delivered', 'read'], true)) {
                $deliveredAt = $push('delivered', $random->int(1, 25));

                if ($final === 'read') {
                    $readAt = $push('read', $random->int(20, 900));
                }
            } elseif ($final !== 'sent') {
                // Un callback perdido lo recupera el reconciliador por polling.
                $push($final, $random->int(3, 60), $attempt['error_code'], $random->chance(0.2) ? 'poll' : 'callback');
            }
        }

        return [
            'accepted_at' => $start,
            'sent_at' => $sentAt,
            'delivered_at' => $deliveredAt,
            'read_at' => $readAt,
            'answered_at' => $answeredAt,
            'last_at' => $cursor,
            'events' => $events,
        ];
    }

    /**
     * Fila de `messaging_charges` (una por SID) y, si Twilio cobró, el uso
     * `messaging_cost_micros` con la misma clave que FinalizeMessagingCharge.
     *
     * @param  array{provider_status: string, delivery_status?: string, error_code: ?string, segments: ?int, duration: ?int}  $attempt
     * @param  array{last_at: CarbonImmutable, events: array<int, array<string, mixed>>}  $timeline
     */
    private function charge(string $sid, string $channel, MessagingChargeSource $source, ?int $sourceId, array $attempt, array $timeline, ShowcaseRandom $random): void
    {
        $status = $attempt['provider_status'];
        $isCall = $channel === 'voice';
        $terminal = in_array($status, ApplyTwilioStatusUpdate::TERMINAL[$isCall ? 'call' : 'message'], true);
        $lastAt = $timeline['last_at'];

        $micros = match (true) {
            ! $terminal => null,
            $isCall => $status === 'completed' ? 14_000 * max(1, (int) ceil(((int) $attempt['duration']) / 60)) : 0,
            in_array($status, ['delivered', 'read'], true) => $channel === 'whatsapp' ? 5_000 : 7_900 * max(1, (int) $attempt['segments']),
            // Twilio cobra el intento aunque el operador no lo entregue
            // (undelivered), pero no lo que ni siquiera salió (failed).
            $status === 'undelivered' => $channel === 'whatsapp' ? 0 : 7_900 * max(1, (int) $attempt['segments']),
            default => 0,
        };
        // Twilio a veces no reporta precio: pasadas 24 h se usa la tabla de estimados.
        $estimated = $micros > 0 && $lastAt->diffInHours($this->ctx->now) > 24 && $random->chance(0.05);
        $finalizedAt = $terminal ? ($estimated ? $lastAt->addHours(24)->addMinutes(5) : $lastAt->addMinutes($random->int(2, 12))) : null;

        if ($finalizedAt?->greaterThan($this->ctx->now)) {
            $finalizedAt = $this->ctx->now->subMinute();
        }

        $inserted = DB::table('messaging_charges')->insertOrIgnore([
            'team_id' => $this->ctx->team->id,
            'provider' => 'twilio',
            'resource_type' => $isCall ? MessagingResourceType::Call->value : MessagingResourceType::Message->value,
            'provider_sid' => $sid,
            'source_type' => $source->value,
            'source_id' => $sourceId,
            'channel_type' => $channel,
            'status' => $status,
            'error_code' => $attempt['error_code'],
            'segments' => $attempt['segments'],
            'duration_seconds' => $isCall ? (int) ($attempt['duration'] ?? 0) : null,
            'price_micros' => $micros,
            'price_unit' => $micros > 0 ? 'USD' : null,
            'price_estimated' => $estimated,
            'finalized_at' => $finalizedAt,
            'metered_at' => $micros > 0 ? $finalizedAt : null,
            'last_checked_at' => $terminal ? $finalizedAt : $lastAt,
            'next_check_at' => $terminal ? null : $this->ctx->now->addMinutes(2),
            'check_attempts' => $terminal ? $random->int(1, 3) : 0,
            'events_json' => json_encode($timeline['events']),
            'created_at' => $timeline['events'][0]['at'],
            'updated_at' => $finalizedAt ?? $lastAt,
        ]);

        if ($inserted === 0) {
            return;
        }

        $this->ctx->count('messaging_charges');

        if ($micros > 0 && $finalizedAt !== null) {
            app(RecordUsageEvent::class)->execute(
                teamId: $this->ctx->team->id,
                meterCode: FinalizeMessagingCharge::METER_CODE,
                quantity: $micros,
                eventKey: "twilio_charge:{$sid}",
                metadata: ['provider_sid' => $sid, 'source_type' => $source->value, 'channel_type' => $channel, 'price_unit' => 'USD', 'estimated' => $estimated, 'showcase' => true],
                occurredAt: $finalizedAt,
            );
        }
    }

    private function replyToken(int $incidentId, int $notificationId, int $recipientId, string $channel, string $phone, CarbonImmutable $at, CarbonImmutable $delivered, ShowcaseRandom $random): void
    {
        $consumed = $random->chance(0.6);
        DB::table('notification_reply_tokens')->insertOrIgnore([
            'team_id' => $this->ctx->team->id,
            'incident_id' => $incidentId,
            'notification_id' => $notificationId,
            'user_id' => null,
            'channel_type' => $channel,
            'address' => $phone,
            'token' => substr(hash('sha256', "reply-{$notificationId}-{$recipientId}-{$channel}"), 0, 16),
            'expires_at' => $at->addMinutes(30),
            'consumed_at' => $consumed ? $delivered->addMinutes($random->int(1, 9)) : null,
            'consumed_action' => $consumed ? 'acknowledge' : null,
            'reply_payload_json' => $consumed ? json_encode(['Body' => '1']) : null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $this->ctx->count('notification_reply_tokens');
    }

    /**
     * Costo de las llamadas de verificación que sembró IncidentsShowcaseSeeder
     * (una fila de cargo por `call_sid`).
     */
    private function seedVerificationCallCharges(): void
    {
        $calls = DB::table('incident_call_verifications')
            ->where('team_id', $this->ctx->team->id)
            ->whereNotNull('metadata_json->showcase')
            ->whereNotNull('call_sid')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('messaging_charges')->whereColumn('messaging_charges.provider_sid', 'incident_call_verifications.call_sid'))
            ->get();

        foreach ($calls as $call) {
            $random = ShowcaseRandom::forKey('verification-charge:'.$call->id);
            $placed = CarbonImmutable::parse($call->placed_at);
            [$status, $duration] = match ($call->status) {
                'answered' => ['completed', $random->int(15, 55)],
                'no_answer' => ['no-answer', 0],
                default => ['failed', 0],
            };
            $timeline = $this->providerTimeline('voice', ['provider_status' => $status, 'error_code' => null, 'duration' => $duration, 'segments' => null, 'read' => false], $placed, $random);
            $this->charge($call->call_sid, 'voice', MessagingChargeSource::VerificationCall, (int) $call->id, ['provider_status' => $status, 'error_code' => $status === 'failed' ? '21214' : null, 'segments' => null, 'duration' => $duration], $timeline, $random);
        }
    }

    /**
     * SMS de verificación de teléfono (OTP) de los usuarios del tenant: una
     * minoría de días alguien verifica o cambia su número.
     */
    private function seedOtpCharges(): void
    {
        $users = array_values(array_filter($this->ctx->users, fn (User $u) => $u->phone !== null));

        if ($users === []) {
            return;
        }

        for ($day = $this->ctx->startDay(); $day->lessThanOrEqualTo($this->ctx->now); $day = $day->addDay()) {
            $random = $this->ctx->random('otp', $day->toDateString());

            if (! $random->chance(0.2)) {
                continue;
            }

            $user = $random->pick($users);
            $at = $day->setTime($random->int(8, 19), $random->int(0, 59));

            if ($at->greaterThan($this->ctx->now->subMinutes(15))) {
                continue;
            }

            $attempt = ['provider_status' => 'delivered', 'error_code' => null, 'segments' => 1, 'duration' => null, 'read' => false];
            $timeline = $this->providerTimeline('sms', $attempt, $at, $random);
            $this->charge('SM'.md5("showcase-otp-{$this->ctx->team->id}-{$day->toDateString()}"), 'sms', MessagingChargeSource::Otp, $user->id, $attempt, $timeline, $random);
        }
    }
}
