<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HealthController extends ApiController
{
    public function __invoke(): JsonResponse
    {
        $checks = ['database' => $this->check(fn () => DB::select('select 1')), 'cache' => $this->check(function () {
            Cache::put('health:probe', 'ok', 10);

            return Cache::get('health:probe') === 'ok';
        }), 'queue' => config('queue.default')];
        $healthy = $checks['database'] === 'ok' && $checks['cache'] === 'ok';

        return response()->json(['success' => $healthy, 'data' => ['status' => $healthy ? 'ok' : 'degraded', 'checks' => $checks]], $healthy ? 200 : 503);
    }

    private function check(callable $callback): string
    {
        try {
            $callback();

            return 'ok';
        } catch (\Throwable) {
            return 'unavailable';
        }
    }
}
