import type { DiningSession, DiningTable, OrderItem } from '../../types/api'
import type { RestaurantEvent } from './useRestaurantRealtime'

type KitchenTicket = OrderItem & { kitchen_station_id?: number | null }

function dataOf(event: RestaurantEvent) {
  return event.data ?? {}
}

function progressFrom(event: RestaurantEvent, fallback?: DiningSession['kitchen_progress']) {
  const progress = dataOf(event).kitchen_progress
  if (progress && typeof progress === 'object') return progress as NonNullable<DiningSession['kitchen_progress']>
  return fallback
}

function asItem(value: unknown): KitchenTicket | null {
  if (!value || typeof value !== 'object' || !('id' in value)) return null
  return value as KitchenTicket
}

function asItems(value: unknown): KitchenTicket[] {
  return Array.isArray(value) ? value.map(asItem).filter((item): item is KitchenTicket => Boolean(item)) : []
}

export function tableWithSession(table: DiningTable, session: DiningSession | null): DiningTable {
  if (!session || session.status === 'closed') {
    return { ...table, display_status: 'available', current_total: '0.00', active_session: null, waiter_called: table.waiter_called }
  }
  const items = session.orders?.flatMap((order) => order.items).filter((item) => item.status !== 'cancelled') ?? []
  const progress = session.kitchen_progress ?? {
    pending: items.filter((item) => item.status === 'pending').length,
    preparing: items.filter((item) => item.status === 'preparing').length,
    ready: items.filter((item) => item.status === 'ready').length,
    served: items.filter((item) => item.status === 'served').length,
  }
  const display = session.status === 'pending_bill' ? 'pending_bill' : progress.served > 0 ? 'food_serving' : 'occupied'
  return { ...table, current_total: session.total_amount, display_status: display, active_session: { ...session, kitchen_progress: progress } }
}

function patchSession(session: DiningSession, event: RestaurantEvent): DiningSession {
  const data = dataOf(event)
  const status = typeof data.session_status === 'string' ? data.session_status as DiningSession['status'] : session.status
  const nextStatus = event.type === 'bill_requested' ? 'pending_bill' : event.type === 'table_closed' ? 'closed' : status
  const itemStatus = event.type === 'item_preparing' ? 'preparing' : event.type === 'item_ready' ? 'ready' : event.type === 'item_served' ? 'served' : event.type === 'item_cancelled' ? 'cancelled' : null
  const incoming = asItems(data.kitchen_items)
  let orders = session.orders ?? []
  if (itemStatus && event.item_id) {
    orders = orders.map((order) => ({
      ...order,
      items: order.items.map((item) => item.id === event.item_id ? { ...item, status: itemStatus } : item),
    }))
  }
  if (event.type === 'order_sent' && incoming.length) {
    const known = new Set(orders.flatMap((order) => order.items.map((item) => item.id)))
    const fresh = incoming.filter((item) => !known.has(item.id))
    if (fresh.length) {
      const ticket = typeof data.ticket_number === 'string' ? data.ticket_number : 'New ticket'
      orders = [...orders, {
        id: event.order_id ?? Date.now(),
        ticket_number: ticket,
        round_number: orders.length + 1,
        status: 'pending',
        items: fresh,
      }]
    }
  }
  const waiterName = typeof data.waiter_name === 'string' ? data.waiter_name : session.waiter?.name
  const waiterId = typeof data.waiter_id === 'number' ? data.waiter_id : event.waiter_id ?? session.waiter_id
  return {
    ...session,
    status: nextStatus,
    total_amount: typeof data.current_total === 'string' ? data.current_total : session.total_amount,
    guest_count: typeof data.guest_count === 'number' ? data.guest_count : session.guest_count,
    waiter_id: waiterId,
    waiter: waiterName ? { id: waiterId ?? session.waiter?.id ?? 0, name: waiterName } : session.waiter,
    kitchen_progress: progressFrom(event, session.kitchen_progress),
    invoice: typeof data.invoice_id === 'number' ? { ...(session.invoice ?? { id: data.invoice_id, invoice_number: String(data.invoice_number ?? ''), status: 'issued', payment_status: 'unpaid', total_amount: String(data.current_total ?? session.total_amount), paid_amount: '0.00', balance_amount: String(data.current_total ?? session.total_amount) }), id: data.invoice_id, invoice_number: String(data.invoice_number ?? session.invoice?.invoice_number ?? '') } : session.invoice,
    orders,
  }
}

export function applyTableEvent(tables: DiningTable[], event: RestaurantEvent): { tables: DiningTable[]; handled: boolean; needsSession: number | null } {
  if (event.type === 'connection_restored') return { tables, handled: true, needsSession: null }
  const tableId = event.table_id
  if (!tableId) return { tables, handled: false, needsSession: null }
  const index = tables.findIndex((table) => table.id === tableId)
  if (index < 0) return { tables, handled: false, needsSession: null }
  const table = tables[index]
  const data = dataOf(event)

  if (event.type === 'waiter_called' || event.type === 'waiter_call_cleared') {
    const next = tables.map((entry) => entry.id === tableId ? { ...entry, waiter_called: event.type === 'waiter_called' } : entry)
    return { tables: next, handled: true, needsSession: null }
  }

  if (event.type === 'table_closed') {
    const next = tables.map((entry) => entry.id === tableId ? { ...entry, display_status: 'available' as const, current_total: '0.00', waiter_called: false, active_session: null } : entry)
    return { tables: next, handled: true, needsSession: null }
  }

  if (event.type === 'session_opened' && !table.active_session && event.session_id) {
    const opened: DiningSession = {
      id: event.session_id,
      dining_table_id: tableId,
      waiter_id: event.waiter_id ?? null,
      guest_count: typeof data.guest_count === 'number' ? data.guest_count : 1,
      status: 'occupied',
      subtotal: '0.00',
      tax_amount: '0.00',
      total_amount: typeof data.current_total === 'string' ? data.current_total : '0.00',
      opened_at: event.occurred_at ?? new Date().toISOString(),
      waiter: typeof data.waiter_name === 'string' ? { id: event.waiter_id ?? 0, name: data.waiter_name } : null,
      orders: [],
      kitchen_progress: progressFrom(event, { pending: 0, preparing: 0, ready: 0, served: 0 }),
    }
    const next = tables.map((entry) => entry.id === tableId ? tableWithSession(entry, opened) : entry)
    return { tables: next, handled: true, needsSession: event.session_id }
  }

  if (!table.active_session) return { tables, handled: false, needsSession: event.session_id ?? null }

  const patched = patchSession(table.active_session, event)
  const display = typeof data.display_status === 'string' ? data.display_status as DiningTable['display_status'] : undefined
  const nextTable: DiningTable = {
    ...table,
    current_total: patched.total_amount,
    display_status: display ?? (patched.status === 'pending_bill' ? 'pending_bill' : (patched.kitchen_progress?.served ?? 0) > 0 ? 'food_serving' : 'occupied'),
    waiter_called: typeof data.waiter_called === 'boolean' ? data.waiter_called : table.waiter_called,
    active_session: patched,
  }
  const next = tables.map((entry) => entry.id === tableId ? nextTable : entry)
  const knownItems = new Set((table.active_session.orders ?? []).flatMap((order) => order.items.map((item) => item.id)))
  const needsSession = event.type === 'order_sent' && asItems(data.kitchen_items).some((item) => !knownItems.has(item.id) && !item.line_total)
    ? patched.id
    : event.type === 'item_ready' || event.type === 'item_preparing' || event.type === 'item_cancelled' || event.type === 'item_served'
      ? (event.item_id && knownItems.has(event.item_id) ? null : patched.id)
      : null
  return { tables: next, handled: true, needsSession }
}

export function applyKitchenQueue<T extends KitchenTicket>(items: T[], event: RestaurantEvent, stationId: number | null): { items: T[]; handled: boolean } {
  if (event.type === 'connection_restored') return { items, handled: true }
  const data = dataOf(event)
  const incomingItems = asItems(data.kitchen_items) as T[]
  const incomingItem = asItem(data.kitchen_item) as T | null

  if (event.type === 'order_sent') {
    if (!incomingItems.length) return { items, handled: false }
    const existing = new Set(items.map((item) => item.id))
    const extra = incomingItems.filter((item) => (!stationId || item.kitchen_station_id === stationId) && !existing.has(item.id) && item.status !== 'cancelled' && item.status !== 'served')
    return { items: extra.length ? [...items, ...extra] : items, handled: true }
  }

  if (event.type === 'item_preparing' || event.type === 'item_ready') {
    const payload = incomingItem
    const id = event.item_id ?? payload?.id
    if (event.station_id && stationId && event.station_id !== stationId) return { items, handled: true }
    if (payload?.kitchen_station_id && stationId && payload.kitchen_station_id !== stationId) {
      return { items: items.filter((item) => item.id !== id), handled: true }
    }
    if (!id) return { items, handled: false }
    const status = event.type === 'item_ready' ? 'ready' : 'preparing'
    const stamp = event.type === 'item_ready' ? { ready_at: event.occurred_at ?? payload?.ready_at } : { preparing_at: event.occurred_at ?? payload?.preparing_at }
    if (items.some((item) => item.id === id)) {
      return { items: items.map((item) => item.id === id ? { ...item, ...payload, status, ...stamp } : item), handled: true }
    }
    if (payload && (!stationId || payload.kitchen_station_id === stationId)) {
      return { items: [...items, { ...payload, status, ...stamp }], handled: true }
    }
    return { items, handled: false }
  }

  if (event.type === 'item_served' || event.type === 'item_cancelled') {
    if (event.station_id && stationId && event.station_id !== stationId) return { items, handled: true }
    return { items: event.item_id ? items.filter((item) => item.id !== event.item_id) : items, handled: true }
  }

  return { items, handled: true }
}
