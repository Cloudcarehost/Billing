export const RESTAURANT_EVENTS = ['session_opened', 'session_assigned', 'order_sent', 'item_preparing', 'item_ready', 'item_served', 'item_cancelled', 'bill_requested', 'invoice_created', 'invoice_reopened', 'table_closed', 'waiter_called', 'waiter_call_cleared', 'outlet_flow_changed'] as const
export type RestaurantEventName = typeof RESTAURANT_EVENTS[number]
export type RestaurantEvent = {
  schema_version?: 1
  event_id?: string
  type?: RestaurantEventName | 'connection_restored'
  hotel_id?: number
  outlet_id?: number
  table_id?: number | null
  session_id?: number | null
  order_id?: number | null
  item_id?: number | null
  waiter_id?: number | null
  station_id?: number | null
  occurred_at?: string
  data?: Record<string, unknown>
}
export type RealtimeStatus = 'connecting' | 'live' | 'reconnecting' | 'offline' | 'unavailable'
