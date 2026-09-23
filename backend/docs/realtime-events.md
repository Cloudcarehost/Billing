# Restaurant realtime event contract

All restaurant events use schema version `1`, are written to the queue only after the surrounding database transaction commits, and are broadcast from the dedicated `broadcasts` queue.

The person who taps Send / Take payment / Print uses the HTTP API response. Reverb only tells other screens to patch. Clients do not wait for the Live badge to finish a sale.

Event names:

- `session_opened`
- `session_assigned`
- `order_sent`
- `item_preparing`
- `item_ready`
- `item_served`
- `item_cancelled`
- `bill_requested`
- `invoice_created`
- `table_closed`
- `waiter_called`
- `waiter_call_cleared`

Every event has the same envelope:

```json
{
  "schema_version": 1,
  "event_id": "uuid",
  "type": "item_ready",
  "hotel_id": 1,
  "outlet_id": 2,
  "table_id": 4,
  "session_id": 25,
  "order_id": 31,
  "item_id": 98,
  "waiter_id": 7,
  "station_id": 3,
  "occurred_at": "2026-09-17T12:30:00.000000Z",
  "data": {
    "table_name": "Table 3",
    "display_status": "food_serving",
    "current_total": "810.00",
    "session_status": "occupied",
    "guest_count": 3,
    "kitchen_progress": { "pending": 0, "preparing": 1, "ready": 2, "served": 0 },
    "kitchen_items": []
  }
}
```

Identifiers that do not apply to an event are `null`; their keys are never omitted. Floor and kitchen clients patch from `data` and refetch only on reconnect or when the patch cannot be applied. Clients use `event_id` to ignore duplicates.

The logged-in app opens one Echo connection and subscribes to every accessible outlet. Even when Live is green, operations screens reconcile from the API every 15 seconds. If Reverb is down they poll every 2.5 seconds.

Required processes:

```bash
php artisan serve
php artisan reverb:start
php artisan queue:work --queue=broadcasts,default --tries=5 --sleep=1
```

Or from `backend/`:

```bash
composer run ops
```

Production must keep API, Reverb, and the worker alive (Supervisor/systemd). Set `QUEUE_CONNECTION=redis` after Redis is provisioned so broadcast jobs are not delayed by the database queue sleeper.

Reverb and the queue worker are independent processes. If Reverb is temporarily unavailable, committed restaurant operations remain successful and broadcast jobs retry without holding database transactions open.
