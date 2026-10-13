<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Services\OpenBillSnapshotService;
use Illuminate\Console\Command;
use Throwable;

class SyncOpenBillsCommand extends Command
{
    protected $signature = 'aswad:sync-open-bills';

    protected $description = 'Rewrite the emergency Google Sheet with currently open table bills.';

    public function handle(OpenBillSnapshotService $snapshots): int
    {
        if (! $snapshots->configured()) {
            $this->warn('Google Sheets backup is not configured. Set GOOGLE_SHEETS_ENABLED, GOOGLE_SHEETS_SPREADSHEET_ID, and GOOGLE_SHEETS_CREDENTIALS_PATH.');

            return self::FAILURE;
        }

        $hotel = Hotel::query()->first();
        if (! $hotel) {
            $this->warn('No hotel found.');

            return self::FAILURE;
        }

        try {
            $snapshots->push($hotel->id);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Open bills copied to Google Sheets.');

        return self::SUCCESS;
    }
}
