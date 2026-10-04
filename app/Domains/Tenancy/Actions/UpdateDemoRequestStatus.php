<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Enums\DemoRequestStatus;
use App\Domains\Tenancy\Models\DemoRequest;
use App\Models\User;
use App\Support\SystemLog;

/**
 * Seguimiento comercial desde la consola: el operador marca una solicitud de
 * demo como contactada, cerrada o la regresa a nueva. Queda quién la movió.
 */
class UpdateDemoRequestStatus
{
    public function execute(DemoRequest $demoRequest, DemoRequestStatus $status, User $actor): void
    {
        $previous = $demoRequest->status;
        $input = ['demo_request_id' => $demoRequest->id, 'actor_id' => $actor->id];

        if ($previous === $status) {
            SystemLog::skipped('tenancy.demo_request.status_changed', reason: 'same_status', input: $input, result: ['status' => $status->value]);

            return;
        }

        $demoRequest->forceFill([
            'status' => $status,
            'status_changed_at' => now(),
            'handled_by_user_id' => $actor->id,
        ])->save();

        SystemLog::ok('tenancy.demo_request.status_changed', input: $input, result: [
            'previous_status' => $previous->value,
            'status' => $status->value,
        ]);
    }
}
