<?php

use App\Models\ReportExport;
use App\Models\IdempotencyKey;
use App\Models\UserDeviceSession;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    ReportExport::query()->whereNotNull('expires_at')->where('expires_at', '<', now())->each(function (ReportExport $export): void {
        if ($export->disk && $export->path) {
            Storage::disk($export->disk)->delete($export->path);
        } $export->delete();
    });
})->daily();

Schedule::call(function (): void {
    if (config('session.driver') !== 'database') {
        return;
    }

    $expiredBefore = now()->subMinutes((int) config('session.lifetime'))->timestamp;
    $sessionIds = DB::table('sessions')->where('last_activity', '<', $expiredBefore)->pluck('id');
    DB::table('sessions')->whereIn('id', $sessionIds)->delete();
    UserDeviceSession::query()->whereIn('session_id', $sessionIds)->delete();
})->hourly();

Schedule::call(fn () => IdempotencyKey::query()->where('expires_at', '<=', now())->delete())->hourly();
