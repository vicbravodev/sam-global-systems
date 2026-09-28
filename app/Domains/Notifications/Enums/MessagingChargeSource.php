<?php

namespace App\Domains\Notifications\Enums;

/**
 * Qué originó un recurso Twilio facturable. Sólo las entregas de
 * notificación reciben el feedback de estado en su propia fila; OTP y
 * llamadas de verificación sólo acumulan costo (la verificación tiene su
 * propio callback de estado en el dominio Incidents).
 */
enum MessagingChargeSource: string
{
    case NotificationDelivery = 'notification_delivery';
    case VerificationCall = 'verification_call';
    case Otp = 'otp';
}
