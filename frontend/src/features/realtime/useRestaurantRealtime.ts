import { useEffect, useRef } from 'react'
import { useRealtimeConnection } from './RestaurantRealtimeProvider'
import type { RestaurantEvent } from './events'

export { RESTAURANT_EVENTS } from './events'
export type { RealtimeStatus, RestaurantEvent, RestaurantEventName } from './events'

type Options = { outletId?: number | null; outletIds?: number[]; tableId?: number | null; stationId?: number | null; onUpdate?: (event: RestaurantEvent) => void }

/** Shared Echo connection; API responses remain the source of truth for the person who tapped. */
export function useRestaurantRealtime({ outletId, outletIds = [], tableId: _tableId, stationId: _stationId, onUpdate }: Options = {}) {
  const { status, subscribe } = useRealtimeConnection()
  const updateRef = useRef(onUpdate)
  const lastSignal = useRef<Date | null>(null)
  const outletIdsKey = Array.from(new Set([...(outletId ? [outletId] : []), ...outletIds])).sort((a, b) => a - b).join(',')

  useEffect(() => { updateRef.current = onUpdate }, [onUpdate])

  useEffect(() => {
    const allowed = outletIdsKey.split(',').filter(Boolean).map(Number)
    return subscribe((event) => {
      if (event.type !== 'connection_restored') {
        if (allowed.length && event.outlet_id && !allowed.includes(event.outlet_id)) return
        lastSignal.current = new Date()
      }
      updateRef.current?.(event)
    })
  }, [outletIdsKey, subscribe])

  return { status, lastSignal: lastSignal.current, connected: status === 'live', online: status !== 'offline' }
}

export function idempotencyHeaders() {
  const id = globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`
  return { 'Idempotency-Key': id }
}
