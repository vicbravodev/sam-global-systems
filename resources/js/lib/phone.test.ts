import { describe, expect, it } from 'vitest';
import {
    applyNationalInput,
    countryFlag,
    formatPhone,
    matchesCountry,
    parsePhoneDraft,
    phoneCountryOption,
    phoneCountryOptions,
    phoneValidity,
    toE164,
} from '@/lib/phone';

describe('parsePhoneDraft', () => {
    it('splits a saved E.164 into country and formatted national number', () => {
        expect(parsePhoneDraft('+528117658890')).toEqual({
            country: 'MX',
            national: '81 1765 8890',
        });
        expect(parsePhoneDraft('+12025550123').country).toBe('US');
    });

    it('defaults to Mexico when empty', () => {
        expect(parsePhoneDraft(null)).toEqual({ country: 'MX', national: '' });
    });

    it('keeps an unparseable legacy value instead of dropping it', () => {
        expect(parsePhoneDraft('abc')).toEqual({
            country: 'MX',
            national: 'abc',
        });
    });
});

describe('applyNationalInput', () => {
    it('formats as the user types', () => {
        expect(applyNationalInput('8117658890', 'MX').national).toBe(
            '81 1765 8890',
        );
    });

    it('switches country when an international number is pasted', () => {
        expect(applyNationalInput('+1 (202) 555-0123', 'MX')).toEqual({
            country: 'US',
            national: '(202) 555-0123',
        });
        expect(applyNationalInput('00 52 81 1765 8890', 'US')).toEqual({
            country: 'MX',
            national: '81 1765 8890',
        });
    });

    it('strips the selected country code pasted without a plus sign', () => {
        expect(applyNationalInput('52 81 1765 8890', 'MX').national).toBe(
            '81 1765 8890',
        );
    });

    it('drops letters and keeps only digits', () => {
        expect(toE164(applyNationalInput('81-17a6.58 890', 'MX'))).toBe(
            '+528117658890',
        );
    });

    it('does not reformat while deleting so separators can be erased', () => {
        expect(
            applyNationalInput('81 17658890', 'MX', { format: false }).national,
        ).toBe('81 17658890');
    });
});

describe('toE164', () => {
    it('builds the canonical value the backend expects', () => {
        expect(toE164({ country: 'MX', national: '81 1765 8890' })).toBe(
            '+528117658890',
        );
        expect(toE164({ country: 'US', national: '(202) 555-0123' })).toBe(
            '+12025550123',
        );
    });

    it('is empty when nothing was captured', () => {
        expect(toE164({ country: 'MX', national: ' ' })).toBe('');
    });
});

describe('phoneValidity', () => {
    it('classifies the capture', () => {
        expect(phoneValidity({ country: 'MX', national: '' })).toBe('empty');
        expect(phoneValidity({ country: 'MX', national: '81 1765 8890' })).toBe(
            'valid',
        );
        expect(phoneValidity({ country: 'MX', national: '81 17' })).toBe(
            'incomplete',
        );
        expect(
            phoneValidity({ country: 'MX', national: '81 1765 8890 1234' }),
        ).toBe('invalid');
    });
});

describe('formatPhone', () => {
    it('renders a saved E.164 for reading', () => {
        expect(formatPhone('+528117658890')).toBe('+52 81 1765 8890');
        expect(formatPhone('')).toBe('');
    });
});

describe('country options', () => {
    it('lists priority countries first with spanish names', () => {
        const options = phoneCountryOptions();

        expect(options[0]).toMatchObject({
            country: 'MX',
            name: 'México',
            dialCode: '+52',
            flag: countryFlag('MX'),
        });
        expect(options.length).toBeGreaterThan(200);
    });

    it('matches by accent-insensitive name, ISO code or dial code', () => {
        const mexico = phoneCountryOption('MX');

        expect(matchesCountry(mexico, 'mexico')).toBe(true);
        expect(matchesCountry(mexico, 'mx')).toBe(true);
        expect(matchesCountry(mexico, '+52')).toBe(true);
        expect(matchesCountry(mexico, 'peru')).toBe(false);
    });
});
