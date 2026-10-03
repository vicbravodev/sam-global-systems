import type { DriverContactEntry } from '@/types/drivers';

export function digits(phone: string): string {
    return phone.replace(/[^+\d]/g, '');
}

export function isPhoneContact(contact: DriverContactEntry): boolean {
    return (
        contact.contactType === 'mobile_phone' ||
        contact.contactType === 'emergency_contact' ||
        contact.contactType === 'supervisor_contact'
    );
}
