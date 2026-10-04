<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Data\HosLadderDecision;
use App\Domains\Drivers\Enums\HosLadderMove;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Drivers\Support\HosLadderPlanner;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Integrations\Data\HosClockReading;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Models\Notification;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Runs the reminder ladder of every open HOS episode of the integration's
 * tenant, once per poll, right after ProcessHosReadings (so the clocks are
 * this minute's and corrected episodes are already closed). What each
 * episode does is decided by {@see HosLadderPlanner}; this action sends the
 * step ({@see SendHosNudge}), raises the incident ({@see RaiseHosIncident}),
 * links it back ({@see LinkHosEpisodeIncident}) and stores the new position.
 *
 * Idempotent: steps are notifications keyed `hos:{episode}:{step}` and the
 * escalation a raw event keyed `hos:{episode}`. A step whose notification
 * reached nobody (failed or cancelled) does not wait for its interval: the
 * next step is due right away (ladders and violation insistence). While a
 * driver has an open violation, his drive/shift episodes hold
 * (`violation_open`): the violation alone tells him, raises the incident and
 * keeps insisting while he drives; break_due is not affected. An
 * episode that throws is logged and counted as failed; the others still
 * advance.
 */
class AdvanceHosEpisodes
{
    /** Situations with a reminder ladder (a violation insists on the ladder's channels): the only ones whose next step can be brought forward. */
    private const array LADDER_SITUATIONS = [HosSituation::BreakDue, HosSituation::DriveLimit, HosSituation::ShiftLimit, HosSituation::Violation];

    /** Situations whose ladder pauses while the same driver has an open violation. */
    private const array HELD_BY_VIOLATION = [HosSituation::DriveLimit, HosSituation::ShiftLimit];

    public function __construct(
        private readonly HosLadderPlanner $planner,
        private readonly SendHosNudge $sendNudge,
        private readonly RaiseHosIncident $raiseIncident,
        private readonly LinkHosEpisodeIncident $linkIncident,
    ) {}

    /**
     * @return array{open: int, notified: int, escalated: int, held: int, waiting: int, failed: int}
     */
    public function execute(TenantIntegration $integration, HosMonitoringConfig $config, CarbonImmutable $now): array
    {
        $teamId = $integration->team_id;

        return TenantContext::for($teamId, function () use ($integration, $config, $now, $teamId): array {
            $episodes = HosEpisode::query()
                ->where('team_id', $teamId)
                ->open()
                ->orderBy('id')
                ->get();

            $states = HosDriverState::query()
                ->where('team_id', $teamId)
                ->whereIn('driver_id', $episodes->pluck('driver_id')->unique()->values()->all())
                ->get()
                ->keyBy('driver_id');

            // Pasado el límite de 11 h/14 h manejando ya ES infracción: ese episodio avisa y levanta el incidente.
            $openViolations = $episodes
                ->filter(fn (HosEpisode $episode): bool => $episode->situation === HosSituation::Violation)
                ->keyBy('driver_id');

            $counts = ['open' => $episodes->count(), 'notified' => 0, 'escalated' => 0, 'held' => 0, 'waiting' => 0, 'failed' => 0];

            foreach ($episodes as $episode) {
                try {
                    $outcome = $this->advance($integration, $episode, $states->get($episode->driver_id), $openViolations->get($episode->driver_id), $config, $now);
                } catch (Throwable $e) {
                    // Un episodio roto no frena la escalera de los demás choferes.
                    SystemLog::failed('hos.ladder.episode_failed', reason: 'unexpected_error', input: [
                        'team_id' => $episode->team_id,
                        'episode_id' => $episode->id,
                        'driver_id' => $episode->driver_id,
                    ], calc: [
                        'situation' => $episode->situation->value,
                        'ladder_step' => $episode->ladder_step,
                    ], error: $e);
                    $outcome = 'failed';
                }

                $counts[$outcome]++;
            }

            SystemLog::ok('hos.ladder.advanced', input: [
                'team_id' => $teamId,
                'integration_id' => $integration->id,
            ], result: $counts, debug: $counts['notified'] === 0 && $counts['escalated'] === 0 && $counts['failed'] === 0);

            return $counts;
        });
    }

    /**
     * @return 'notified'|'escalated'|'held'|'waiting'|'failed'
     */
    private function advance(TenantIntegration $integration, HosEpisode $episode, ?HosDriverState $state, ?HosEpisode $openViolation, HosMonitoringConfig $config, CarbonImmutable $now): string
    {
        if ($episode->escalated_at !== null && $episode->incident_id === null) {
            $this->linkIncident->execute($episode);
        }

        if ($openViolation !== null && in_array($episode->situation, self::HELD_BY_VIOLATION, true)) {
            return $this->holdForViolation($episode, $openViolation, $state);
        }

        $this->skipAheadAfterUndeliveredNudge($episode, $now);

        $current = $this->currentReading($state);
        $decision = $this->planner->plan(
            $episode->situation,
            $episode->ladder_step,
            $episode->next_nudge_at,
            $episode->opened_at,
            $episode->escalated_at !== null,
            $current,
            $config,
            $now,
        );

        $input = ['team_id' => $episode->team_id, 'episode_id' => $episode->id, 'driver_id' => $episode->driver_id];
        $calc = [
            'situation' => $episode->situation->value,
            'move' => $decision->move->value,
            'ladder_step' => $episode->ladder_step,
            'next_step' => $decision->nextStep,
            'duty_status' => $current?->dutyStatus,
            'app_disconnected' => $state?->app_disconnected_since !== null,
            'state_present' => $state !== null,
        ];

        return match ($decision->move) {
            HosLadderMove::Notify => $this->notify($integration, $episode, $decision, $input, $calc),
            HosLadderMove::Escalate => $this->escalate($integration, $episode, $decision, $current, $now, $input, $calc),
            HosLadderMove::Hold, HosLadderMove::Wait, HosLadderMove::Done => $this->hold($episode, $decision, $input, $calc),
        };
    }

    /**
     * The driver's open violation owns the notice and the incident: the
     * drive/shift ladder would tell him "si no, avisaremos" right after
     * "ya avisamos" and raise a second (`hos_unattended`) incident. Paused
     * without touching `ladder_step`/`next_nudge_at`: once the violation
     * resolves it resumes where it was.
     *
     * @return 'held'
     */
    private function holdForViolation(HosEpisode $episode, HosEpisode $violation, ?HosDriverState $state): string
    {
        SystemLog::skipped('hos.nudge.skipped', reason: 'violation_open', input: [
            'team_id' => $episode->team_id,
            'episode_id' => $episode->id,
            'driver_id' => $episode->driver_id,
        ], calc: [
            'situation' => $episode->situation->value,
            'move' => HosLadderMove::Hold->value,
            'ladder_step' => $episode->ladder_step,
            'next_step' => $episode->ladder_step,
            'violation_episode_id' => $violation->id,
            'duty_status' => $state?->duty_status?->value,
            'app_disconnected' => $state?->app_disconnected_since !== null,
            'state_present' => $state !== null,
        ], debug: true);

        return 'held';
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $calc
     * @return 'held'|'waiting'
     */
    private function hold(HosEpisode $episode, HosLadderDecision $decision, array $input, array $calc): string
    {
        $this->schedule($episode, $decision);

        SystemLog::skipped('hos.nudge.skipped', reason: $decision->reason, input: $input, calc: $calc, debug: true);

        return $decision->move === HosLadderMove::Hold ? 'held' : 'waiting';
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $calc
     * @return 'notified'|'failed'
     */
    private function notify(TenantIntegration $integration, HosEpisode $episode, HosLadderDecision $decision, array $input, array $calc): string
    {
        $calc += ['step' => $decision->step, 'channels' => $decision->channels, 'notice' => $decision->notice?->value];

        try {
            $notification = $this->sendNudge->execute($integration, $episode, $decision);
        } catch (Throwable $e) {
            // El escalón no avanza: el siguiente ciclo lo reintenta con la misma clave.
            SystemLog::failed('hos.nudge.failed', reason: 'dispatch_error', input: $input, calc: $calc, error: $e);

            return 'failed';
        }

        $this->schedule($episode, $decision);

        SystemLog::ok('hos.nudge.sent', input: $input, calc: $calc, result: [
            'notification_id' => $notification->id,
            'notification_reused' => ! $notification->wasRecentlyCreated,
            'next_nudge_at' => $decision->nextNudgeAt?->toIso8601String(),
        ]);

        return 'notified';
    }

    /**
     * The incident first, on its own: a nudge that throws (Samsara or Twilio
     * down) never keeps the monitoring team from hearing about it. The
     * escalation step's notice goes next, also on its own; if it fails the
     * episode still counts as escalated (the incident is raised) and, for a
     * violation, the insistence steps that follow keep telling the driver.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $calc
     * @return 'escalated'|'failed'
     */
    private function escalate(TenantIntegration $integration, HosEpisode $episode, HosLadderDecision $decision, ?HosClockReading $current, CarbonImmutable $now, array $input, array $calc): string
    {
        $calc += ['step' => $decision->step, 'channels' => $decision->channels, 'notice' => $decision->notice?->value];

        try {
            $raised = $this->raiseIncident->execute($episode, $current, $now);
        } catch (Throwable $e) {
            SystemLog::failed('hos.nudge.failed', reason: 'escalation_error', input: $input, calc: $calc, error: $e);

            return 'failed';
        }

        if ($decision->channels !== [] && $decision->notice !== null) {
            try {
                $notification = $this->sendNudge->execute($integration, $episode, $decision);

                SystemLog::ok('hos.nudge.sent', input: $input, calc: $calc, result: [
                    'notification_id' => $notification->id,
                    'notification_reused' => ! $notification->wasRecentlyCreated,
                    'next_nudge_at' => $decision->nextNudgeAt?->toIso8601String(),
                ]);
            } catch (Throwable $e) {
                // El incidente ya salió: el aviso perdido no frena el escalado.
                SystemLog::failed('hos.nudge.failed', reason: 'dispatch_error', input: $input, calc: $calc, error: $e);
            }
        }

        if (! $raised['raised'] && $raised['reason'] === 'no_asset') {
            // Sin unidad no hay incidente: no se marca escalado, se reintenta el siguiente ciclo.
            SystemLog::degraded('hos.ladder.escalation_failed', reason: 'no_asset', input: $input, calc: $calc);

            return 'failed';
        }

        // El episodio sigue abierto: su corrección cierra o anota el incidente.
        // Una infracción guarda cuándo insiste de nuevo (sólo si sigue manejando).
        $episode->forceFill([
            'escalated_at' => $now,
            'ladder_step' => $decision->nextStep,
            'next_nudge_at' => $decision->nextNudgeAt,
        ])->save();

        return 'escalated';
    }

    /**
     * The previous step reached nobody (Samsara without "Write Messages",
     * driver without phone, suppressed number, WhatsApp outside its window):
     * waiting its interval would only delay the next channel.
     */
    private function skipAheadAfterUndeliveredNudge(HosEpisode $episode, CarbonImmutable $now): void
    {
        // Sólo las escaleras usan next_nudge_at como "siguiente escalón"; fin de pausa lo recalcula desde opened_at.
        if (! in_array($episode->situation, self::LADDER_SITUATIONS, true)) {
            return;
        }

        if ($episode->ladder_step === 0 || $episode->next_nudge_at === null || $episode->next_nudge_at->lte($now)) {
            return;
        }

        $previousStep = $episode->ladder_step - 1;
        $previous = Notification::query()
            ->where('team_id', $episode->team_id)
            ->where('event_key', SendHosNudge::eventKey($episode, $previousStep))
            ->first();

        if ($previous === null || ! in_array($previous->status, [NotificationStatus::Failed, NotificationStatus::Cancelled], true)) {
            return;
        }

        $episode->forceFill(['next_nudge_at' => $now])->save();

        SystemLog::degraded('hos.nudge.channel_unavailable', reason: 'previous_nudge_undelivered', input: [
            'team_id' => $episode->team_id,
            'episode_id' => $episode->id,
            'driver_id' => $episode->driver_id,
        ], calc: [
            'previous_step' => $previousStep,
            'notification_id' => $previous->id,
            'notification_status' => $previous->status->value,
        ]);
    }

    /**
     * This minute's clocks, or null when unknown: no state yet, or the driver
     * app is disconnected (the stored clocks are frozen). The planner only
     * reads the duty status and the clocks, never the external ids.
     */
    private function currentReading(?HosDriverState $state): ?HosClockReading
    {
        if ($state === null || $state->app_disconnected_since !== null || $state->duty_status === null) {
            return null;
        }

        return $state->toReading('', null);
    }

    private function schedule(HosEpisode $episode, HosLadderDecision $decision): void
    {
        $episode->ladder_step = $decision->nextStep;
        $episode->next_nudge_at = $decision->nextNudgeAt;

        if ($episode->isDirty(['ladder_step', 'next_nudge_at'])) {
            $episode->save();
        }
    }
}
