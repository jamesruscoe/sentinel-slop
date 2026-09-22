<?php

namespace App\Events;

use App\Models\Scan;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after every pipeline stage. Broadcast immediately from the scan
 * worker so the scans queue has no dependency on the default queue.
 */
class ScanProgressed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly Scan $scan, public readonly ?string $message = null) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('scans.'.$this->scan->uuid)];
    }

    public function broadcastAs(): string
    {
        return 'scan.progressed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'uuid' => $this->scan->uuid,
            'status' => $this->scan->status->value,
            'label' => $this->scan->status->label(),
            'progress' => $this->scan->status->progress(),
            'message' => $this->message,
            'slop_score' => $this->scan->slop_score,
            'error_message' => $this->scan->error_message,
        ];
    }
}
