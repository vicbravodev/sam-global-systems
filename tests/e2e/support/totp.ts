import { createHmac } from 'node:crypto';

const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/** Código TOTP de 6 dígitos (RFC 6238, SHA-1, 30 s), como Google2FA. */
export function totp(secret: string, at = Date.now()): string {
    const bits = [...secret]
        .map((char) => BASE32.indexOf(char).toString(2).padStart(5, '0'))
        .join('');
    const key = Buffer.from(
        (bits.match(/.{8}/g) ?? []).map((byte) => parseInt(byte, 2)),
    );
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(at / 30_000)));

    const hmac = createHmac('sha1', key).update(counter).digest();
    const offset = (hmac.at(-1) ?? 0) & 0x0f;
    const code = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;

    return String(code).padStart(6, '0');
}
