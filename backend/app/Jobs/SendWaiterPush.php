<?php

namespace App\Jobs;

use App\Services\WaiterPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendWaiterPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 20;

    /** @param  array{title: string, body: string, tag?: string, url?: string}  $payload */
    public function __construct(
        public readonly int $userId,
        public readonly array $payload,
    ) {
        $this->onQueue('default');
    }

    public function handle(WaiterPushService $push): void
    {
        try {
            $push->sendToUser($this->userId, $this->payload);
        } catch (Throwable) {
            // Pocket alerts must never fail kitchen or billing work.
        }
    }
}
