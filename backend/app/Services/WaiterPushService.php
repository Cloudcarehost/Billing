<?php

namespace App\Services;

use App\Jobs\SendWaiterPush;
use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class WaiterPushService
{
    public function enabled(): bool
    {
        return filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
    }

    public function publicKey(): ?string
    {
        return $this->enabled() ? (string) config('webpush.vapid.public_key') : null;
    }

    public function subscribe(int $userId, int $hotelId, string $endpoint, string $publicKey, string $authToken, ?string $encoding, ?string $userAgent): PushSubscription
    {
        return PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => hash('sha256', $endpoint)],
            [
                'user_id' => $userId,
                'hotel_id' => $hotelId,
                'endpoint' => $endpoint,
                'public_key' => $publicKey,
                'auth_token' => $authToken,
                'content_encoding' => $encoding ?: 'aes128gcm',
                'user_agent' => $userAgent,
                'last_used_at' => now(),
            ],
        );
    }

    public function unsubscribe(int $userId, string $endpoint): void
    {
        PushSubscription::query()->where('user_id', $userId)->where('endpoint_hash', hash('sha256', $endpoint))->delete();
    }

    /** Queue a pocket notification after the business write commits. Failures never bubble into kitchen or billing. */
    public static function notify(?int $userId, array $payload): void
    {
        try {
            if (! $userId || ! filled(config('webpush.vapid.public_key')) || ! filled(config('webpush.vapid.private_key'))) {
                return;
            }

            SendWaiterPush::dispatch($userId, $payload)->afterCommit(! app()->runningUnitTests());
        } catch (Throwable) {
            // Pocket alerts must not roll back or delay order, kitchen, or billing work.
        }
    }

    /** @param  array{title: string, body: string, tag?: string, url?: string}  $payload */
    public function sendToUser(int $userId, array $payload): void
    {
        if (! $this->enabled()) {
            return;
        }

        $subscriptions = PushSubscription::query()->where('user_id', $userId)->get();
        if ($subscriptions->isEmpty()) {
            return;
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => (string) (config('webpush.vapid.subject') ?: config('app.url')),
                    'publicKey' => (string) config('webpush.vapid.public_key'),
                    'privateKey' => (string) config('webpush.vapid.private_key'),
                ],
            ]);
            $webPush->setReuseVAPIDHeaders(true);
            $webPush->setAutomaticPadding(false);

            foreach ($subscriptions as $subscription) {
                try {
                    $webPush->queueNotification(
                        Subscription::create([
                            'endpoint' => $subscription->endpoint,
                            'publicKey' => $subscription->public_key,
                            'authToken' => $subscription->auth_token,
                            'contentEncoding' => $subscription->content_encoding ?: 'aes128gcm',
                        ]),
                        json_encode($payload, JSON_THROW_ON_ERROR),
                    );
                } catch (Throwable) {
                    $subscription->delete();
                }
            }

            foreach ($webPush->flush() as $report) {
                $endpoint = $report->getRequest()->getUri()->__toString();
                $match = $subscriptions->first(fn (PushSubscription $row) => $row->endpoint === $endpoint);
                if (! $report->isSuccess()) {
                    $match?->delete();
                    continue;
                }
                $match?->update(['last_used_at' => now()]);
            }
        } catch (Throwable) {
            // Push provider failures stay on the worker.
        }
    }
}
