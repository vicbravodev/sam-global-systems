<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Models\DemoRequest;
use App\Models\User;
use App\Notifications\DemoRequestReceived;
use App\Support\SystemLog;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Guarda una solicitud de demo del sitio público y avisa por correo a todos
 * los super-admins. La solicitud queda guardada aunque el correo falle: la
 * consola es la fuente de verdad y el correo sólo es la alerta.
 */
class SubmitDemoRequest
{
    /**
     * @param  array{name: string, company: string, email: string, phone?: ?string, fleet_size: string, message?: ?string}  $data
     */
    public function execute(array $data, ?string $ipAddress): DemoRequest
    {
        $demoRequest = DemoRequest::query()->create([
            'name' => $data['name'],
            'company' => $data['company'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'fleet_size' => $data['fleet_size'],
            'message' => $data['message'] ?? null,
            'ip_address' => $ipAddress,
        ]);

        $input = ['demo_request_id' => $demoRequest->id, 'fleet_size' => $demoRequest->fleet_size];

        SystemLog::ok('tenancy.demo_request.received', input: $input, calc: [
            'has_phone' => $demoRequest->phone !== null,
            'has_message' => $demoRequest->message !== null,
        ]);

        $this->notify($demoRequest, $input);

        return $demoRequest;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function notify(DemoRequest $demoRequest, array $input): void
    {
        $superAdmins = User::query()->where('global_role', 'super_admin')->get();

        if ($superAdmins->isEmpty()) {
            SystemLog::degraded('tenancy.demo_request.notified', reason: 'no_recipients', input: $input, result: ['recipients' => 0]);

            return;
        }

        try {
            Notification::sendNow($superAdmins, new DemoRequestReceived($demoRequest));
        } catch (Throwable $e) {
            SystemLog::failed('tenancy.demo_request.notify_failed', reason: 'send_failed', input: [...$input, 'recipients' => $superAdmins->count()], error: $e);

            return;
        }

        $demoRequest->forceFill([
            'notified_recipients' => $superAdmins->count(),
            'notified_at' => now(),
        ])->save();

        SystemLog::ok('tenancy.demo_request.notified', input: $input, result: ['recipients' => $superAdmins->count()]);
    }
}
