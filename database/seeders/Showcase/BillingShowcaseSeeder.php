<?php

namespace Database\Seeders\Showcase;

use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Jobs\AggregateUsageJob;
use App\Domains\Tenancy\Jobs\GenerateInvoiceSnapshotJob;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\InvoiceSnapshot;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Domains\Tenancy\Models\UsageMeter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Facturación: suscripción, features del plan, eventos de uso de CADA
 * medidor facturable derivados de la actividad que sembraron los pasos
 * anteriores (eventos ingeridos, llamadas de IA, tokens, incidentes,
 * acciones, entregas por canal, llamadas, media, Copilot…), y a partir de
 * ahí los mismos jobs que corren en producción: `AggregateUsageJob`
 * (agregados diarios + contador del mes en curso) y
 * `GenerateInvoiceSnapshotJob` por cada mes cerrado (el job no se despacha
 * en ningún lado de la app: aquí se invoca a mano). Después se reparte el
 * estado de las facturas: pagadas por transferencia, una anulada, la
 * última pendiente.
 *
 * Historial: hasta 6 meses. Los meses anteriores a la ventana simulada se
 * rellenan con el promedio diario de la ventana y una curva de adopción.
 *
 * Idempotencia: `usage_events.event_key = showcase:{team}:usage:{medidor}:{Ymd}`
 * (medidores de activos: la misma clave que `assets:record-usage-meters`)
 * con `insertOrIgnore`; contadores, facturas, suscripción y features sólo
 * si faltan.
 */
class BillingShowcaseSeeder extends ShowcaseStep
{
    private const HISTORY_MONTHS = 6;

    public function run(): void
    {
        $subscription = $this->ensureSubscription();
        $this->ensureFeatures($subscription);

        $meters = UsageMeter::query()->get()->keyBy('code');
        $daily = $this->dailyUsage($meters->keys()->all());
        $this->recordUsage($daily, $subscription);

        (new AggregateUsageJob($this->ctx->team->id))->handle();

        $this->pastCounters($meters, $subscription);
        $this->invoices($subscription);
    }

    private function ensureSubscription(): Subscription
    {
        $existing = Subscription::query()->where('team_id', $this->ctx->team->id)->orderByDesc('id')->first();

        if ($existing !== null) {
            return $existing;
        }

        $plan = Plan::query()->where('code', $this->ctx->subscriptionPlan)->first() ?? Plan::query()->orderBy('base_price')->firstOrFail();
        $status = $this->ctx->subscriptionStatus;
        $starts = $this->ctx->now->subMonths(self::HISTORY_MONTHS)->startOfMonth()->addDays(3);

        $subscription = Subscription::query()->create([
            'team_id' => $this->ctx->team->id,
            'plan_id' => $plan->id,
            'status' => $status,
            'billing_cycle' => 'monthly',
            'starts_at' => $starts,
            'renews_at' => $this->ctx->now->addMonthNoOverflow()->startOfMonth(),
            'cancel_at_period_end' => false,
            'external_provider' => 'bank_transfer',
            // Marca las facturas de esta suscripción como del showcase (se pueden regenerar).
            'external_subscription_id' => 'showcase-'.$this->ctx->team->id,
            'created_at' => $starts,
        ]);
        $this->ctx->count('team_subscriptions');

        return $subscription;
    }

    private function ensureFeatures(Subscription $subscription): void
    {
        if (TenantFeature::query()->where('team_id', $this->ctx->team->id)->exists()) {
            return;
        }

        $activeAssets = $this->ctx->assets->filter(fn ($a) => $a->status?->value !== 'inactive')->count();

        foreach (BillingRate::query()->with('usageMeter')->where('plan_id', $subscription->plan_id)->get() as $rate) {
            $code = $rate->usageMeter?->code;

            if ($code === null) {
                continue;
            }

            // Una flota real más grande que el plan recibe una ampliación
            // negociada: sin ella el tope de activos frenaría la sincronización real.
            $override = $code === 'monitored_assets' && $activeAssets > (int) $rate->included_quantity;

            TenantFeature::query()->create([
                'team_id' => $this->ctx->team->id,
                'feature_key' => $code,
                'enabled' => true,
                'source' => $override ? 'manual_override' : 'default_plan',
                'limits_json' => $override
                    ? ['included_quantity' => (int) (ceil($activeAssets * 1.2 / 50) * 50), 'note' => 'Ampliación negociada con el cliente']
                    : ($rate->included_quantity > 0 ? ['included_quantity' => (int) $rate->included_quantity] : null),
            ]);
            $this->ctx->count('tenant_features');
        }

        foreach ([
            ['ai_media_analysis', true, 'default_plan'],
            ['voice_verification', true, 'default_plan'],
            ['custom_branding', false, 'default_plan'],
            ['api_access', true, 'beta_access'],
            ['driver_coaching_reports', true, 'promo'],
        ] as [$key, $enabled, $source]) {
            TenantFeature::query()->create([
                'team_id' => $this->ctx->team->id,
                'feature_key' => $key,
                'enabled' => $enabled,
                'source' => $source,
                'limits_json' => null,
            ]);
            $this->ctx->count('tenant_features');
        }
    }

    /**
     * Cantidad por medidor y día a partir de lo que el showcase sembró.
     *
     * @param  array<int, string>  $meterCodes
     * @return array<string, array<string, int>> medidor => [Y-m-d => cantidad]
     */
    private function dailyUsage(array $meterCodes): array
    {
        $team = $this->ctx->team->id;
        $day = fn (string $column) => DB::raw("DATE({$column}) as day");
        $from = $this->ctx->startDay();
        $usage = [];

        $usage['ingested_events'] = DB::table('raw_events')
            ->join('event_sources', 'event_sources.id', '=', 'raw_events.event_source_id')
            ->where('raw_events.team_id', $team)
            ->where('event_sources.source_name', 'like', 'showcase-%')
            ->where('event_sources.source_name', '!=', 'showcase-internal-monitor')
            ->where('raw_events.received_at', '>=', $from)
            ->groupBy('day')->select($day('raw_events.received_at'), DB::raw('count(*) as qty'))
            ->pluck('qty', 'day')->all();

        $inference = DB::table('ai_inference_logs')
            ->join('ai_event_evaluations', 'ai_event_evaluations.id', '=', 'ai_inference_logs.evaluation_id')
            ->where('ai_event_evaluations.team_id', $team)
            ->whereNotNull('ai_event_evaluations.signals_json->showcase')
            ->where('ai_inference_logs.created_at', '>=', $from)
            ->groupBy('day')
            ->select($day('ai_inference_logs.created_at'), DB::raw('count(*) as calls'), DB::raw('sum(input_tokens) as tin'), DB::raw('sum(output_tokens) as tout'))
            ->get();
        $copilot = DB::table('copilot_messages')
            ->where('team_id', $team)->where('role', 'assistant')->whereNotNull('context_json->showcase')
            ->where('created_at', '>=', $from)
            ->groupBy('day')
            ->select($day('created_at'), DB::raw('count(*) as queries'), DB::raw('sum(input_tokens) as tin'), DB::raw('sum(output_tokens) as tout'))
            ->get();

        foreach ($inference as $row) {
            $usage['ai_calls'][$row->day] = (int) $row->calls;
            $usage['ai_tokens_in'][$row->day] = (int) $row->tin;
            $usage['ai_tokens_out'][$row->day] = (int) $row->tout;
        }

        foreach ($copilot as $row) {
            $usage['copilot_queries'][$row->day] = (int) $row->queries;
            $usage['ai_tokens_in'][$row->day] = ($usage['ai_tokens_in'][$row->day] ?? 0) + (int) $row->tin;
            $usage['ai_tokens_out'][$row->day] = ($usage['ai_tokens_out'][$row->day] ?? 0) + (int) $row->tout;
        }

        $usage['incident_workflows'] = DB::table('incidents')
            ->where('team_id', $team)->whereNotNull('metadata_json->showcase_key')->where('opened_at', '>=', $from)
            ->groupBy('day')->select($day('opened_at'), DB::raw('count(*) as qty'))->pluck('qty', 'day')->all();

        $usage['automation_actions'] = DB::table('action_executions')
            ->join('automation_workflows', 'automation_workflows.id', '=', 'action_executions.automation_workflow_id')
            ->where('action_executions.team_id', $team)->where('action_executions.status', 'completed')
            ->where('action_executions.created_at', '>=', $from)
            ->groupBy('day')->select($day('action_executions.created_at'), DB::raw('count(*) as qty'))->pluck('qty', 'day')->all();

        $deliveries = DB::table('notification_deliveries')
            ->join('notifications', 'notifications.id', '=', 'notification_deliveries.notification_id')
            ->join('notification_channels', 'notification_channels.id', '=', 'notification_deliveries.channel_id')
            ->where('notification_deliveries.team_id', $team)
            ->where('notifications.event_key', 'like', 'showcase:%')
            ->whereNotIn('notification_deliveries.status', ['skipped', 'queued', 'pending', 'cancelled'])
            ->groupBy('day', 'notification_channels.channel_type')
            ->select($day('notification_deliveries.created_at'), 'notification_channels.channel_type', DB::raw('count(*) as qty'))
            ->get();

        foreach ($deliveries as $row) {
            $meter = match ($row->channel_type) {
                'sms' => 'sms_messages',
                'whatsapp' => 'whatsapp_messages',
                'voice' => 'voice_notification_calls',
                default => 'outbound_notifications',
            };
            $usage[$meter][$row->day] = ($usage[$meter][$row->day] ?? 0) + (int) $row->qty;
        }

        $usage['voice_calls'] = DB::table('incident_call_verifications')
            ->where('team_id', $team)->whereNotNull('metadata_json->showcase')->where('placed_at', '>=', $from)
            ->groupBy('day')->select($day('placed_at'), DB::raw('count(*) as qty'))->pluck('qty', 'day')->all();

        $usage['media_requests'] = DB::table('event_media_requests')
            ->where('team_id', $team)->whereNotNull('response_metadata_json->showcase')->where('requested_at', '>=', $from)
            ->groupBy('day')->select($day('requested_at'), DB::raw('count(*) as qty'))->pluck('qty', 'day')->all();

        // Medidores de activos: una foto diaria, igual que assets:record-usage-meters.
        $monitored = $this->ctx->assets->filter(fn ($a) => $a->status?->value !== 'inactive')->count();
        $cameras = $this->ctx->assets->filter(fn ($a) => $a->status?->value !== 'inactive' && $a->assetType?->category?->value === 'camera')->count();

        for ($d = $from; $d->lessThanOrEqualTo($this->ctx->now); $d = $d->addDay()) {
            if ($monitored > 0) {
                $usage['monitored_assets'][$d->toDateString()] = $monitored;
            }

            if ($cameras > 0) {
                $usage['active_cameras'][$d->toDateString()] = $cameras;
            }

        }

        // OTP y costo Twilio salen de los cargos que sembró NotificationsShowcaseSeeder.
        $usage['otp_sms_sent'] = DB::table('messaging_charges')
            ->where('team_id', $team)->where('source_type', 'otp')->where('created_at', '>=', $from)
            ->groupBy('day')->select($day('created_at'), DB::raw('count(*) as qty'))->pluck('qty', 'day')->all();
        $usage['messaging_cost_micros'] = DB::table('messaging_charges')
            ->where('team_id', $team)->whereNotNull('metered_at')->where('metered_at', '>=', $from)
            ->groupBy('day')->select($day('metered_at'), DB::raw('sum(price_micros) as qty'))->pluck('qty', 'day')->all();

        return array_intersect_key($usage, array_flip($meterCodes));
    }

    /**
     * @param  array<string, array<string, int>>  $daily
     */
    private function recordUsage(array $daily, Subscription $subscription): void
    {
        $record = app(RecordUsageEvent::class);
        $firstDay = CarbonImmutable::parse($subscription->starts_at)->startOfDay();
        $windowStart = $this->ctx->startDay();
        $before = DB::table('usage_events')->where('team_id', $this->ctx->team->id)->count();

        foreach ($daily as $meter => $days) {
            $values = array_values(array_filter($days));
            $average = $values === [] ? 0 : array_sum($values) / count($values);

            // Historial previo a la ventana: adopción creciente hasta el promedio actual.
            for ($d = $firstDay; $d->lessThan($windowStart); $d = $d->addDay()) {
                $progress = $windowStart->diffInDays($d, true) / max(1, $windowStart->diffInDays($firstDay, true));
                $factor = 1 - 0.45 * $progress;
                $quantity = in_array($meter, ['monitored_assets', 'active_cameras'], true)
                    ? (int) round($average * (1 - 0.2 * $progress))
                    : (int) round($average * $factor * ($this->ctx->random('history', $meter, $d->toDateString())->chance(0.5) ? 1.1 : 0.9));

                if ($quantity > 0) {
                    $days[$d->toDateString()] = $quantity;
                }
            }

            foreach ($days as $date => $quantity) {
                if ($quantity <= 0 || CarbonImmutable::parse($date)->lessThan($firstDay)) {
                    continue;
                }

                // Dentro de la ventana, el costo Twilio ya está medido por cargo
                // (`twilio_charge:{sid}`); aquí sólo se rellena el historial previo.
                if ($meter === 'messaging_cost_micros' && ! CarbonImmutable::parse($date)->lessThan($windowStart)) {
                    continue;
                }

                $key = in_array($meter, ['monitored_assets', 'active_cameras'], true)
                    ? "{$meter}:{$this->ctx->team->id}:{$date}"
                    : $this->ctx->key('usage', $meter, str_replace('-', '', $date));

                $record->execute(
                    teamId: $this->ctx->team->id,
                    meterCode: $meter,
                    quantity: (int) $quantity,
                    eventKey: $key,
                    metadata: ['showcase' => true],
                    occurredAt: CarbonImmutable::parse($date)->setTime(23, 0),
                );
            }
        }

        $this->ctx->count('usage_events', DB::table('usage_events')->where('team_id', $this->ctx->team->id)->count() - $before);
    }

    /**
     * `AggregateUsageJob` sólo recalcula el mes en curso: los meses cerrados
     * se escriben aquí con su misma fórmula (suma del periodo vs. incluido).
     *
     * @param  Collection<string, UsageMeter>  $meters
     */
    private function pastCounters($meters, Subscription $subscription): void
    {
        $rates = BillingRate::query()->where('plan_id', $subscription->plan_id)->pluck('included_quantity', 'usage_meter_id');
        $month = CarbonImmutable::parse($subscription->starts_at)->startOfMonth();
        $current = $this->ctx->now->startOfMonth();

        for (; $month->lessThan($current); $month = $month->addMonth()) {
            foreach ($meters as $meter) {
                $exists = TenantUsageCounter::query()
                    ->where('team_id', $this->ctx->team->id)
                    ->where('usage_meter_id', $meter->id)
                    ->whereDate('period_start', $month->toDateString())
                    ->exists();

                if ($exists) {
                    continue;
                }

                // Misma fórmula que AggregateUsageJob (suma), incluso en medidores `max`.
                $consumed = (int) DB::table('usage_events')
                    ->where('team_id', $this->ctx->team->id)
                    ->where('usage_meter_id', $meter->id)
                    ->whereBetween('occurred_at', [$month, $month->endOfMonth()])
                    ->sum('quantity');

                if ($consumed === 0) {
                    continue;
                }

                $included = (int) ($rates[$meter->id] ?? 0);

                TenantUsageCounter::query()->create([
                    'team_id' => $this->ctx->team->id,
                    'usage_meter_id' => $meter->id,
                    'period_start' => $month->toDateString(),
                    'period_end' => $month->endOfMonth()->toDateString(),
                    'consumed_value' => $consumed,
                    'included_value' => $included,
                    'overage_value' => max(0, $consumed - $included),
                    'last_calculated_at' => $month->endOfMonth(),
                ]);
                $this->ctx->count('tenant_usage_counters');
            }
        }
    }

    private function invoices(Subscription $subscription): void
    {
        $month = CarbonImmutable::parse($subscription->starts_at)->startOfMonth();
        $current = $this->ctx->now->startOfMonth();
        $owned = str_starts_with((string) $subscription->external_subscription_id, 'showcase-');
        $created = [];

        for (; $month->lessThanOrEqualTo($current); $month = $month->addMonth()) {
            $start = $month->toDateString();
            $end = $month->endOfMonth()->toDateString();
            $existing = InvoiceSnapshot::query()->where('team_id', $this->ctx->team->id)->whereDate('period_start', $start)->first();

            // Facturas del showcase anteriores a la línea cost-plus de Twilio: se
            // regeneran con el job para que la incluyan. Las demás nunca se tocan.
            if ($existing !== null && $owned && $existing->subscription_id === $subscription->id && ! $this->hasCostPlusLine($existing)) {
                $existing->delete();
                $existing = null;
            }

            if ($existing !== null) {
                continue;
            }

            (new GenerateInvoiceSnapshotJob($this->ctx->team->id, $start, $end))->handle();
            $invoice = InvoiceSnapshot::query()->where('team_id', $this->ctx->team->id)->whereDate('period_start', $start)->first();

            if ($invoice !== null) {
                $created[$invoice->id] = true;
                $this->ctx->count('invoice_snapshots');
            }
        }

        $closed = InvoiceSnapshot::query()
            ->where('team_id', $this->ctx->team->id)
            ->where('subscription_id', $subscription->id)
            ->whereDate('period_start', '<', $current->toDateString())
            ->orderBy('period_start')
            ->get()
            ->values();
        $last = $closed->count() - 1;

        foreach ($closed as $i => $invoice) {
            if (! isset($created[$invoice->id])) {
                continue;
            }

            $periodEnd = CarbonImmutable::parse($invoice->period_end);
            $pastDue = $this->ctx->subscriptionStatus === 'past_due';

            [$status, $paidAt, $note] = match (true) {
                $i === 0 && $closed->count() > 2 => ['void', null, 'Anulada: mes de cortesía por implementación.'],
                $i === $last => ['invoiced', null, $pastDue ? 'Vencida: sin comprobante de pago a la fecha.' : 'Pendiente de transferencia.'],
                $pastDue && $i === $last - 1 => ['invoiced', null, 'Vencida: recordatorio enviado al área de finanzas.'],
                default => ['paid', $periodEnd->addDays(5 + $i % 6)->setTime(12, 30), sprintf('Transferencia SPEI ref. %s', strtoupper(substr(md5("{$invoice->id}"), 0, 10)))],
            };

            $invoice->forceFill([
                'status' => $status,
                'paid_at' => $paidAt,
                'payment_note' => $note,
                'generated_at' => $periodEnd->addDay()->setTime(2, 15),
            ])->save();
        }
    }

    private function hasCostPlusLine(InvoiceSnapshot $invoice): bool
    {
        foreach ((array) $invoice->breakdown_json as $line) {
            if (($line['billing_model'] ?? null) === 'cost_plus') {
                return true;
            }
        }

        return false;
    }
}
