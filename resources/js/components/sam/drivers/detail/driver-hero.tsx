import { Link } from '@inertiajs/react';
import { MessageCircle, Phone, Truck } from 'lucide-react';
import { DetailHeader } from '@/components/sam/detail-header';
import { digits } from '@/components/sam/drivers/detail/phone';
import { DriverStatusBadge } from '@/components/sam/drivers/driver-status-badge';
import { EntityAvatar } from '@/components/sam/entity-avatar';
import { RelativeTime } from '@/components/sam/relative-time';
import { resolveRiskLevel } from '@/components/sam/risk-gauge';
import { SeverityBadge } from '@/components/sam/severity-badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';
import { isFresh, minutesSince } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { DriverDetail } from '@/types/drivers';

export function DriverHero({
    driver,
    teamSlug,
}: {
    driver: DriverDetail;
    teamSlug: string | null;
}) {
    const phone =
        driver.phone ??
        driver.contacts.find((c) => c.contactType === 'mobile_phone')?.value ??
        null;
    const riskLevel = resolveRiskLevel(
        driver.riskProfile?.riskLevel,
        driver.riskProfile?.riskScore ?? null,
    );
    // "visto" = latest real activity (event or unit signal), not the
    // roster-sync timestamp, which lags hours behind the road.
    const seenAt = driver.lastSignalAt;
    const fresh = isFresh(seenAt);

    return (
        <DetailHeader
            backHref={teamSlug ? `/${teamSlug}/drivers` : '#'}
            backLabel="Volver a conductores"
            media={<EntityAvatar name={driver.fullName} size={52} />}
            title={driver.fullName}
            badges={
                <>
                    <DriverStatusBadge status={driver.status} />
                    {riskLevel &&
                        (riskLevel === 'high' || riskLevel === 'critical') && (
                            <SeverityBadge level={riskLevel} />
                        )}
                </>
            }
            meta={
                <>
                    {driver.employeeCode && (
                        <span className="font-mono">{driver.employeeCode}</span>
                    )}
                    {driver.currentAsset ? (
                        <Link
                            href={
                                teamSlug
                                    ? `/${teamSlug}/assets/${driver.currentAsset.id}`
                                    : '#'
                            }
                            className="inline-flex items-center gap-1 text-fg-2 hover:text-primary hover:underline"
                        >
                            <Truck size={12} strokeWidth={1.75} />
                            {driver.currentAsset.name}
                            {driver.currentAsset.code && (
                                <span className="font-mono text-fg-3">
                                    {driver.currentAsset.code}
                                </span>
                            )}
                        </Link>
                    ) : (
                        <span className="italic">Sin unidad asignada</span>
                    )}
                    {seenAt && (
                        <span
                            className="inline-flex items-center gap-1.5"
                            title={formatDateTime(seenAt)}
                        >
                            <span
                                className={cn(
                                    'size-1.5 rounded-full',
                                    fresh
                                        ? 'bg-severity-low'
                                        : 'bg-fg-disabled',
                                )}
                                aria-hidden="true"
                            />
                            visto{' '}
                            <RelativeTime minutes={minutesSince(seenAt)} />
                        </span>
                    )}
                </>
            }
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    {phone && (
                        <>
                            <Button variant="outline" size="sm" asChild>
                                <a href={`tel:${digits(phone)}`}>
                                    <Phone size={13} />
                                    Llamar
                                </a>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <a
                                    href={`https://wa.me/${digits(phone).replace('+', '')}`}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <MessageCircle size={13} />
                                    WhatsApp
                                </a>
                            </Button>
                        </>
                    )}
                    {driver.currentAsset && teamSlug && (
                        <Button variant="outline" size="sm" asChild>
                            <Link
                                href={`/${teamSlug}/assets/${driver.currentAsset.id}`}
                            >
                                <Truck size={13} />
                                Ver unidad
                            </Link>
                        </Button>
                    )}
                </div>
            }
        />
    );
}
