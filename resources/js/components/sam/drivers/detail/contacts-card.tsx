import { Phone } from 'lucide-react';
import { CONTACT_TYPE_LABELS } from '@/components/sam/drivers/copy';
import { digits, isPhoneContact } from '@/components/sam/drivers/detail/phone';
import { StatusBadge } from '@/components/sam/status-badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { DriverContactEntry } from '@/types/drivers';

export function ContactsCard({ contacts }: { contacts: DriverContactEntry[] }) {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="flex flex-row items-center justify-between border-b border-border px-4 py-3">
                <CardTitle className="sam-h3 m-0 flex items-center gap-2">
                    <Phone size={15} /> Contactos
                </CardTitle>
                <span className="sam-meta">
                    {contacts.length}{' '}
                    {contacts.length === 1 ? 'contacto' : 'contactos'}
                </span>
            </CardHeader>
            <CardContent className="p-0">
                {contacts.length === 0 ? (
                    <p className="px-4 py-5 text-sm text-fg-3">
                        Sin contactos. Se sincronizan desde el proveedor de
                        telemetría o se cargan a mano; el contacto de emergencia
                        es el que usa la escalación.
                    </p>
                ) : (
                    <ul className="divide-y divide-border">
                        {contacts.map((contact) => (
                            <li
                                key={contact.id}
                                className="flex items-center gap-3 px-4 py-2.5"
                            >
                                <span className="flex min-w-0 flex-1 flex-col">
                                    <span className="text-2xs text-fg-3">
                                        {CONTACT_TYPE_LABELS[
                                            contact.contactType
                                        ] ?? contact.contactType}
                                        {contact.label && ` · ${contact.label}`}
                                    </span>
                                    {isPhoneContact(contact) ? (
                                        <a
                                            href={`tel:${digits(contact.value)}`}
                                            className="truncate font-mono text-sm text-fg-1 tabular-nums hover:text-primary"
                                        >
                                            {contact.value}
                                        </a>
                                    ) : contact.contactType === 'email' ? (
                                        <a
                                            href={`mailto:${contact.value}`}
                                            className="truncate text-sm text-fg-1 hover:text-primary"
                                        >
                                            {contact.value}
                                        </a>
                                    ) : (
                                        <span className="truncate text-sm text-fg-1">
                                            {contact.value}
                                        </span>
                                    )}
                                </span>
                                {contact.isPrimary && (
                                    <StatusBadge
                                        size="sm"
                                        tone="primary"
                                        label="Primario"
                                    />
                                )}
                                {contact.isEmergency && (
                                    <StatusBadge
                                        size="sm"
                                        tone="critical"
                                        label="Emergencia"
                                    />
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}
