<?php

namespace App\Domains\Notifications\Jobs;

use App\Domains\Notifications\Actions\DispatchNotification;
use App\Domains\Notifications\Models\Notification;
use App\Support\JobFailureReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $notificationId,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(DispatchNotification $dispatch): void
    {
        $notification = Notification::withoutGlobalScopes()->find($this->notificationId);

        if ($notification === null) {
            return;
        }

        $dispatch->execute($notification);
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, [
            'notification_id' => $this->notificationId,
        ]);
    }
}
