<?php

namespace App\Http\Controllers\Api;

use App\Services\WaiterPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends ApiController
{
    public function config(WaiterPushService $push): JsonResponse
    {
        return $this->success([
            'enabled' => $push->enabled(),
            'public_key' => $push->publicKey(),
        ]);
    }

    public function store(Request $request, WaiterPushService $push): JsonResponse
    {
        if (! $push->enabled()) {
            return $this->success(['subscribed' => false], 'Pocket alerts are not configured on this server.');
        }

        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:2048'],
            'keys.p256dh' => ['required', 'string', 'max:512'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', 'max:32'],
        ]);
        $hotel = $request->attributes->get('currentHotel');
        $push->subscribe(
            $request->user()->id,
            $hotel->id,
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth'],
            $data['content_encoding'] ?? 'aes128gcm',
            substr((string) $request->userAgent(), 0, 255),
        );

        return $this->success(['subscribed' => true], 'Pocket alerts enabled on this phone.');
    }

    public function destroy(Request $request, WaiterPushService $push): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);
        $push->unsubscribe($request->user()->id, $data['endpoint']);

        return $this->success(['subscribed' => false]);
    }
}
