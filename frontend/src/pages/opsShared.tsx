import { useEffect } from 'react'
import { Wifi, WifiOff } from 'lucide-react'
import { api } from '../lib/api'
import type { ApiEnvelope, DiningTable, Session } from '../types/api'
import type { RealtimeStatus } from '../features/realtime/events'

export const value = (amount: string | number | undefined) => Number(amount ?? 0)
export const money = (amount: string | number | undefined, currency = 'INR') => new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 2 }).format(value(amount))
export const readable = (text: string) => text.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
export const unwrap = <T,>(response: { data: ApiEnvelope<T> }) => response.data.data
export function isParcelTable(table?: Pick<DiningTable, 'service_type'> | null) {
  return table?.service_type === 'parcel'
}
export function isDirectBillOutlet(outlets: Session['outlets'] | undefined, outletId: number | null | undefined) {
  if (!outletId || !outlets) return false
  return outlets.find((outlet) => outlet.id === outletId)?.order_flow === 'direct_bill'
}
export function readyToServeCount(table: DiningTable, skipReady = false): number {
  if (skipReady) return 0
  const session = table.active_session
  if (!session) return 0
  if (session.kitchen_progress) return session.kitchen_progress.ready
  return session.orders?.flatMap((order) => order.items).filter((item) => item.status === 'ready').length ?? 0
}

export function acknowledgeWaiterCall(tableId: number) {
  return api.post(`/api/v1/tables/${tableId}/acknowledge-waiter-call`)
}

export function TableAlertMarks({ ready, waiterCalled }: { ready: number; waiterCalled: boolean }) {
  if (ready < 1 && !waiterCalled) return null
  return <span className="table-alert-marks">
    {waiterCalled ? <em className="table-alert-mark call-waiter-mark" title="Guest called the waiter" aria-label="Guest called the waiter">!</em> : null}
    {ready > 0 ? <em className="table-alert-mark serve-now-mark" title={`${ready} item${ready > 1 ? 's' : ''} ready — go serve`} aria-label={`${ready} ready to serve`}>!</em> : null}
  </span>
}

const statusCopy: Record<RealtimeStatus, { label: string; tone: string }> = {
  connecting: { label: 'Connecting', tone: 'offline' },
  live: { label: 'Live', tone: 'live' },
  reconnecting: { label: 'Reconnecting', tone: 'offline' },
  offline: { label: 'Offline', tone: 'offline' },
  unavailable: { label: 'Live updates unavailable — refreshing automatically', tone: 'offline' },
}

export function ConnectionState({ status }: { status: RealtimeStatus }) {
  const copy = statusCopy[status]
  return <span className={`connection-state ${copy.tone}`}>{status === 'live' ? <Wifi size={14} /> : <WifiOff size={14} />}{copy.label}</span>
}

export function useOperationalRefresh(task: () => void | Promise<void>, status: RealtimeStatus) {
  useEffect(() => {
    if (typeof document === 'undefined') return
    const delay = status === 'live' || status === 'connecting' ? 15_000 : 8_000
    let cancelled = false
    let inflight = false
    const tick = () => {
      if (cancelled || document.hidden || inflight) return
      inflight = true
      Promise.resolve(task()).finally(() => { inflight = false })
    }
    const timer = window.setInterval(tick, delay)
    return () => { cancelled = true; window.clearInterval(timer) }
  }, [task, status])
}
