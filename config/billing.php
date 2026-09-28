<?php

/*
|--------------------------------------------------------------------------
| Facturación por tracto-día (decisión de producto 2026-09-28)
|--------------------------------------------------------------------------
|
| SAM cobra por cada día que una unidad está vigilada, no por planes. Estos
| son los valores de plataforma; cada tenant puede sobreescribirlos en
| `tenant_billing_terms` (consola super-admin). Los planes sólo sirven como
| plantilla de topes al dar de alta un tenant.
|
| Todas las tarifas se expresan en `currency`; el costo de Twilio (USD) se
| convierte con `fx_usd_rate`.
|
*/

return [
    // Moneda de facturación (ISO 4217, minúsculas como el resto del sistema).
    'currency' => strtolower((string) env('BILLING_CURRENCY', 'mxn')),

    // Precio mensual por tracto vigilado. Se prorratea por día:
    // tarifa diaria = unit_price / días del mes.
    'unit_price' => (float) env('BILLING_UNIT_PRICE', 450),

    // Mínimo facturable en tractos por mes (0 = sin mínimo). Cubre costos
    // fijos con flotas muy pequeñas.
    'min_billable_assets' => (int) env('BILLING_MIN_BILLABLE_ASSETS', 0),

    // Zona horaria del "día" facturable: la muestra diaria y los tracto-días
    // por uso se fechan en hora local del cliente, no en UTC.
    'timezone' => env('BILLING_TIMEZONE', 'America/Mexico_City'),

    // Emergencia (pánico, colisión, vuelco) de una unidad NO vigilada: se
    // atiende siempre y ese día la unidad se cobra como tracto-día más este
    // recargo (decisión 2026-09-28).
    'unmonitored_emergency_surcharge_percent' => (float) env('BILLING_UNMONITORED_EMERGENCY_SURCHARGE', 10),

    // Uso justo de IA: evaluaciones incluidas por tracto vigilado y mes
    // (agrupadas por tenant). El excedente se cobra por evaluación.
    'ai_fair_use_per_asset' => (int) env('BILLING_AI_FAIR_USE_PER_ASSET', 60),
    'ai_overage_unit_price' => (float) env('BILLING_AI_OVERAGE_UNIT_PRICE', 5),

    // Tipo de cambio USD → currency para trasladar el costo real de Twilio.
    'fx_usd_rate' => (float) env('BILLING_FX_USD_RATE', 18.5),

    // Escalones por volumen (promedio de tractos vigilados en el mes). Vacío
    // = precio plano `unit_price`. Ejemplo:
    // [['from' => 1, 'to' => 25, 'unit_price' => 500], ['from' => 26, 'to' => 100, 'unit_price' => 450], ['from' => 101, 'to' => null, 'unit_price' => 400]]
    'volume_tiers' => [],
];
