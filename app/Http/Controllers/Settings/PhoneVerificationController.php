<?php

namespace App\Http\Controllers\Settings;

use App\Domains\Access\Actions\SendPhoneOtp;
use App\Domains\Access\Actions\VerifyPhoneOtp;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PhoneVerificationController extends Controller
{
    public function send(Request $request, SendPhoneOtp $sendPhoneOtp): RedirectResponse
    {
        $user = $request->user();
        $result = $sendPhoneOtp->execute($user, (int) $user->current_team_id);

        if (! $result->ok) {
            return back()->withErrors(['phone' => $this->messageFor($result->reason)]);
        }

        return back()->with('status', 'phone-otp-sent');
    }

    public function verify(Request $request, VerifyPhoneOtp $verifyPhoneOtp): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $user = $request->user();
        $result = $verifyPhoneOtp->execute($user, (int) $user->current_team_id, $validated['code']);

        if (! $result->ok) {
            return back()->withErrors(['code' => $this->messageFor($result->reason)]);
        }

        return back()->with('status', 'phone-verified');
    }

    private function messageFor(string $reason): string
    {
        return match ($reason) {
            'no_phone' => 'Agrega un teléfono en formato E.164 antes de verificar.',
            'no_sms_channel' => 'No hay un canal SMS configurado; contacta a tu administrador.',
            'delivery_failed' => 'No pudimos enviar el SMS. Intenta de nuevo en un momento.',
            'expired' => 'El código expiró. Solicita uno nuevo.',
            'too_many_attempts' => 'Demasiados intentos. Solicita un código nuevo.',
            'invalid_code' => 'El código no es correcto.',
            default => 'No se pudo completar la verificación.',
        };
    }
}
