<?php

namespace App\Domains\Tenancy\Support;

use App\Domains\Incidents\Support\IncidentSupervisors;

/**
 * Canal de arranque del cliente (decisión 2026-09-28): para que SAM empiece a
 * vigilar, al menos un admin o supervisor del tenant debe tener TELÉFONO
 * verificado (llamada/SMS/WhatsApp de emergencia) y CORREO verificado. Sin
 * eso no hay a quién avisar cuando algo pasa.
 */
final class TenantContactReadiness
{
    /**
     * @return array{ready: bool, phone: bool, email: bool}
     */
    public static function for(int $teamId): array
    {
        $phone = false;
        $email = false;

        foreach (IncidentSupervisors::users($teamId) as $user) {
            $phone = $phone || $user->verifiedPhone() !== null;
            $email = $email || $user->email_verified_at !== null;

            if ($phone && $email) {
                break;
            }
        }

        return ['ready' => $phone && $email, 'phone' => $phone, 'email' => $email];
    }

    /**
     * @param  array{ready: bool, phone: bool, email: bool}  $readiness  lo que devuelve `for()`
     */
    public static function message(array $readiness): string
    {
        $missing = array_keys(array_filter([
            'un teléfono verificado' => ! $readiness['phone'],
            'un correo verificado' => ! $readiness['email'],
        ]));

        return 'Antes de encender la vigilancia, un administrador del equipo necesita '
            .implode(' y ', $missing)
            .' (Configuración → Perfil). Es el canal por el que SAM te avisa de una emergencia.';
    }
}
