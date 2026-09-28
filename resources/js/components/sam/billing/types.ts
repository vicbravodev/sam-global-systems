/**
 * Props de la página de Facturación (BillingPageController). El cobro es por
 * tracto-día: cada noche se cuentan las unidades vigiladas y el mes se cobra
 * como Σ tracto-días × (precio mensual ÷ días del mes).
 */

export interface SubscriptionProp {
    planName: string | null;
    planCode: string | null;
    basePrice: number | null;
    currency: string | null;
    billingCycle: string;
    billingCycleLabel: string | null;
    status: string;
    statusLabel: string | null;
    renewsAt: string | null;
}

export interface FeatureRow {
    key: string;
    enabled: boolean;
    source: string;
    sourceLabel: string | null;
    limits: Record<string, unknown> | null;
}

export interface UsageRow {
    meterCode: string | null;
    /** El plan factura este medidor (tiene tarifa). */
    billed: boolean;
    /** El excedente tiene precio. */
    overageCharged: boolean;
    /** Conteo por canal cuyo costo va en la línea de mensajería (Twilio). */
    billedVia: 'messaging' | null;
    meterName: string | null;
    unit: string | null;
    /** Medidores a costo (Twilio): importe a cobrar, en USD. */
    amount: number | null;
    consumed: number;
    included: number;
    overage: number;
    periodStart: string | null;
    periodEnd: string | null;
}

export interface InvoiceLine {
    meter_code?: string;
    meter_name?: string;
    billing_model?: string;
    consumed?: number;
    included?: number;
    overage?: number;
    days_in_period?: number;
    daily_rate?: number;
    overage_unit_price?: number;
    overage_cost?: number;
    amount?: number;
    [key: string]: unknown;
}

export interface InvoiceRow {
    id: number;
    periodStart: string | null;
    periodEnd: string | null;
    subtotal: number;
    overageTotal: number;
    total: number;
    currency: string | null;
    status: string;
    statusLabel: string | null;
    /** Emitida (o borrador de periodo cerrado) y sin pagar. */
    awaitsPayment: boolean;
    paidAt: string | null;
    hasReceipt: boolean;
    paymentNote: string | null;
    breakdown: InvoiceLine[] | Record<string, unknown> | null;
}

export interface BillingTerms {
    unit_price: number;
    currency: string;
    included_assets: number | null;
    min_billable_assets: number;
    ai_fair_use_per_asset: number;
    ai_overage_unit_price: number;
    messaging_markup_percent: number | null;
    fx_usd_rate: number;
    volume_tiers: { from: number; to: number | null; unit_price: number }[];
    explicit: boolean;
}

export interface PeriodEstimate {
    periodStart: string;
    periodEnd: string;
    daysInPeriod: number;
    /** Noches con conteo de unidades vigiladas en el mes. */
    daysRecorded: number;
    /** Días del calendario transcurridos, contando hoy. */
    daysElapsed: number;
    /** Días que aún se cobrarán con las unidades vigiladas ahora. */
    remainingDays: number;
    currency: string;
    unitPrice: number;
    dailyRate: number;
    monitoredNow: number;
    cap: number | null;
    overCap: boolean;
    assetDays: number;
    assetDaysExtra: number;
    projectedAssetDays: number;
    projectedAssetDaysExtra: number;
    assetsToDate: number;
    assetsProjected: number;
    assetsExtraToDate: number;
    assetsExtraProjected: number;
    aiCalls: number;
    aiIncluded: number;
    aiOverage: number;
    aiToDate: number;
    aiProjected: number;
    messagingToDate: number;
    totalToDate: number;
    totalProjected: number;
    minBillableAssets: number;
    aiFairUsePerAsset: number;
    aiOverageUnitPrice: number;
}

export interface FleetCounts {
    monitored: number;
    pending: number;
    excluded: number;
}

export interface TransferDetails {
    beneficiary: string | null;
    bank: string | null;
    clabe: string;
}

export interface BillingPageProps {
    supportEmail: string | null;
    transfer: TransferDetails | null;
    subscription: SubscriptionProp | null;
    features: FeatureRow[];
    usage: UsageRow[];
    invoices: InvoiceRow[];
    terms: BillingTerms;
    estimate: PeriodEstimate;
    fleet: FleetCounts;
}
