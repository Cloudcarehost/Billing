<?php

namespace Tests\Feature\Api;

use App\Jobs\SendWaiterPush;
use App\Models\DiningTable;
use App\Models\Hotel;
use App\Models\KitchenStation;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WaiterPushTest extends TestCase
{
    use RefreshDatabase;

    public function test_kitchen_ready_queues_pocket_push_without_changing_the_ticket_response(): void
    {
        Queue::fake();
        config()->set('webpush.vapid.public_key', 'test-public');
        config()->set('webpush.vapid.private_key', 'test-private');
        [$owner, $item] = $this->readyContext();

        $this->actingAs($owner, 'web')->postJson("/api/v1/order-items/{$item->id}/kitchen-status", ['status' => 'preparing'])->assertOk();
        $this->actingAs($owner, 'web')->postJson("/api/v1/order-items/{$item->id}/kitchen-status", ['status' => 'ready'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready');

        Queue::assertPushed(SendWaiterPush::class, fn (SendWaiterPush $job) => $job->userId === $owner->id && str_contains($job->payload['title'], 'Table'));
    }

    public function test_kitchen_ready_still_succeeds_when_pocket_alerts_are_not_configured(): void
    {
        Queue::fake();
        config()->set('webpush.vapid.public_key', null);
        config()->set('webpush.vapid.private_key', null);
        [$owner, $item] = $this->readyContext();

        $this->actingAs($owner, 'web')->postJson("/api/v1/order-items/{$item->id}/kitchen-status", ['status' => 'preparing'])->assertOk();
        $this->actingAs($owner, 'web')->postJson("/api/v1/order-items/{$item->id}/kitchen-status", ['status' => 'ready'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready');

        Queue::assertNotPushed(SendWaiterPush::class);
    }

    public function test_push_subscription_can_be_saved_for_the_logged_in_waiter(): void
    {
        config()->set('webpush.vapid.public_key', 'test-public');
        config()->set('webpush.vapid.private_key', 'test-private');
        [$owner] = $this->readyContext();

        $this->actingAs($owner, 'web')->postJson('/api/v1/push/subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/aswad-test',
            'keys' => ['p256dh' => 'public-device-key', 'auth' => 'auth-token'],
        ])->assertOk()->assertJsonPath('data.subscribed', true);

        $this->assertDatabaseHas('push_subscriptions', ['user_id' => $owner->id]);
        $this->assertSame(1, PushSubscription::query()->count());
    }

    /** @return array{User, OrderItem} */
    private function readyContext(): array
    {
        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/v1/onboarding', [
            'hotel_name' => 'Lakeview Hotel', 'outlet_name' => 'Main Restaurant', 'outlet_code' => 'MAIN',
            'owner_name' => 'Asha Owner', 'owner_email' => 'asha-push@example.test',
            'password' => 'StrongPassword1', 'password_confirmation' => 'StrongPassword1',
        ])->assertCreated();

        $owner = User::query()->where('email', 'asha-push@example.test')->sole();
        $hotel = Hotel::query()->sole();
        $outlet = $hotel->outlets()->sole();
        $station = KitchenStation::query()->create(['outlet_id' => $outlet->id, 'name' => 'Hot Kitchen', 'code' => 'HOT']);
        $product = Product::query()->create(['hotel_id' => $hotel->id, 'kitchen_station_id' => $station->id, 'fulfillment_mode' => 'kitchen', 'name' => 'Curry', 'selling_price' => 100, 'track_inventory' => false]);
        $table = DiningTable::query()->create(['outlet_id' => $outlet->id, 'name' => 'Table 3', 'code' => 'T3', 'capacity' => 4]);
        $sessionId = $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/sessions")->json('data.id');
        $this->actingAs($owner, 'web')->withHeader('Idempotency-Key', 'push-order')->postJson("/api/v1/dining-sessions/{$sessionId}/orders", ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertCreated();

        return [$owner, OrderItem::query()->sole()];
    }
}
