<?php

namespace App\Services;

use App\Models\Notification;
use App\Support\AuditTrail;

class NotificationService
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function create(array $attributes): Notification
    {
        $model = Notification::query()->create($attributes);
        $this->audit->record('notification.created', ['id' => $model->id]);

        return $model;
    }

    public function limit(): int
    {
        return (int) config('kennel.notification_limit', 10);
    }
}
