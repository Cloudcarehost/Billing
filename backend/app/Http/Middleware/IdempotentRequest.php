<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class IdempotentRequest
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '') {
            return $this->error('An Idempotency-Key header is required for this operation.', 422);
        }
        if (strlen($key) > 100 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $key)) {
            return $this->error('The Idempotency-Key header format is invalid.', 422);
        }

        $hotel = $request->attributes->get('currentHotel');
        $hash = hash('sha256', $request->method()."\n".$request->path()."\n".$request->getContent());
        $record = $this->claim((int) $hotel->id, $scope, $key, $hash);
        if ($record->request_hash !== $hash) {
            return $this->error('This idempotency key was already used with a different request.', 409);
        }
        if ($record->status === 'completed') {
            return response()->json($record->response, $record->response_status ?? 200, ['Idempotency-Replayed' => 'true']);
        }
        if (! $record->wasRecentlyCreated) {
            return $this->error('A request with this idempotency key is still processing.', 409, ['Retry-After' => '2']);
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $record->delete();
            throw $exception;
        }
        if ($response->getStatusCode() >= 500) {
            $record->delete();
            return $response;
        }

        $payload = json_decode((string) $response->getContent(), true);
        $record->update(['status' => 'completed', 'response' => is_array($payload) ? $payload : ['data' => $payload], 'response_status' => $response->getStatusCode(), 'expires_at' => now()->addDay()]);
        return $response;
    }

    private function claim(int $hotelId, string $scope, string $key, string $hash): IdempotencyKey
    {
        IdempotencyKey::query()->where('expires_at', '<=', now())->where('status', '!=', 'processing')->delete();

        return DB::transaction(function () use ($hotelId, $scope, $key, $hash) {
            try {
                return IdempotencyKey::query()->create(['hotel_id' => $hotelId, 'scope' => $scope, 'key' => $key, 'status' => 'processing', 'request_hash' => $hash, 'locked_at' => now(), 'expires_at' => now()->addMinutes(10)]);
            } catch (QueryException) {
                return IdempotencyKey::query()->where('hotel_id', $hotelId)->where('scope', $scope)->where('key', $key)->lockForUpdate()->firstOrFail();
            }
        });
    }

    /** @param array<string, string> $headers */
    private function error(string $message, int $status, array $headers = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status, $headers);
    }
}
