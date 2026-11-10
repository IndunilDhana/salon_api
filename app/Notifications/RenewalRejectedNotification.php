<?php

namespace App\Notifications;

use App\Models\SubscriptionRenewal;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RenewalRejectedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public SubscriptionRenewal $renewal
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'renewal_rejected',
            'renewal_id' => $this->renewal->id,
            'tenant_id' => $this->renewal->tenant_id,
            'status' => $this->renewal->status,
            'message' => 'Your subscription renewal request was rejected and access remains limited until the next review.',
        ];
    }
}
