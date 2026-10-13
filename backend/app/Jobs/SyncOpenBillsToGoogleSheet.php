<?php

namespace App\Jobs;

use App\Services\OpenBillSnapshotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncOpenBillsToGoogleSheet implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 120;

    public function __construct(public readonly int $hotelId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'open-bills-sheet:'.$this->hotelId;
    }

    public function handle(OpenBillSnapshotService $snapshots): void
    {
        try {
            $snapshots->push($this->hotelId);
        } catch (Throwable) {
            // Emergency sheet copy must never fail kitchen or billing work.
        }
    }
}
