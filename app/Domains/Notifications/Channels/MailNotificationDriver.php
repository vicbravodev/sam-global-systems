<?php

namespace App\Domains\Notifications\Channels;

use App\Contracts\Notifications\NotificationDriver;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Mail\GenericNotificationMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class MailNotificationDriver implements NotificationDriver
{
    public function send(RenderedNotification $notification): DeliveryResult
    {
        try {
            Mail::to($notification->address, $notification->recipientName)
                ->send(new GenericNotificationMail(
                    subjectLine: $notification->subject ?? 'Notification',
                    bodyText: $notification->body,
                ));

            return DeliveryResult::success(
                providerMessageId: 'mail-'.(string) Str::uuid(),
                response: ['driver' => 'mail'],
            );
        } catch (\Throwable $exception) {
            return DeliveryResult::failure($exception->getMessage());
        }
    }
}
