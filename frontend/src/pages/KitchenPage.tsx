import { Check, Clock3, RefreshCw, X } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { PromptDialog, Toast } from '../components/ui/Feedback'
import { useAuth } from '../features/auth/AuthContext'
import { canCancelItem } from '../features/auth/permissions'
import { useRestaurantRealtime } from '../features/realtime/useRestaurantRealtime'
import { api, errorMessage } from '../lib/api'
import type { ApiEnvelope, KitchenStation, OrderItem } from '../types/api'
import { ConnectionState, unwrap, useOperationalRefresh } from './opsShared'
import { applyKitchenQueue } from '../features/realtime/applyRestaurantEvent'
import { playFloorSound, soundFor, unlockFloorAlert } from '../features/notifications/floorAlerts'

type KitchenItem = OrderItem & { order?: { id: number; ticket_number: string; round_number: number; sent_at?: string; creator?: { name: string }; dining_session?: { dining_table?: { name: string; code: string } } } }

function groupKitchenTickets(items: KitchenItem[]) {
  return items.map((item) => [item])
}

export function KitchenPage() {
  const { session, activeOutletId } = useAuth()
  const [stations, setStations] = useState<KitchenStation[]>([])
  const [stationId, setStationId] = useState<number | null>(null)
  const [items, setItems] = useState<KitchenItem[]>([])
  const [filter, setFilter] = useState<'all' | 'pending' | 'preparing' | 'ready'>('all')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [busyId, setBusyId] = useState<number | null>(null)
  const [cancelTarget, setCancelTarget] = useState<KitchenItem | null>(null)
  const seenTicketIds = useRef<Set<number> | null>(null)
  useEffect(() => {
    const unlock = () => { void unlockFloorAlert() }
    window.addEventListener('pointerdown', unlock, { once: true })
    window.addEventListener('keydown', unlock, { once: true })
    return () => {
      window.removeEventListener('pointerdown', unlock)
      window.removeEventListener('keydown', unlock)
    }
  }, [])
  const loadStations = useCallback(async () => { try { const result = unwrap(await api.get<ApiEnvelope<KitchenStation[]>>('/api/v1/kitchen-stations', { params: { outlet_id: activeOutletId } })); setStations(result); setStationId((current) => current && result.some((station) => station.id === current) ? current : result[0]?.id ?? null) } catch (requestError) { setError(errorMessage(requestError)) } }, [activeOutletId])
  const loadQueue = useCallback(async () => { if (!stationId) return; try { const result = unwrap(await api.get<ApiEnvelope<{ items: KitchenItem[] }>>(`/api/v1/kitchen-stations/${stationId}/queue`)); setItems(result.items) } catch (requestError) { setError(errorMessage(requestError)) } }, [stationId])
  useEffect(() => { const task = window.setTimeout(() => void loadStations(), 0); return () => window.clearTimeout(task) }, [loadStations])
  useEffect(() => { const task = window.setTimeout(() => void loadQueue(), 0); return () => window.clearTimeout(task) }, [loadQueue])
  useEffect(() => { seenTicketIds.current = null }, [stationId])
  useEffect(() => {
    if (seenTicketIds.current) {
      const arrived = items.filter((item) => item.status === 'pending' && !seenTicketIds.current!.has(item.id))
      if (arrived.length) playFloorSound(soundFor(session?.hotel, 'kitchen'))
    }
    seenTicketIds.current = new Set(items.map((item) => item.id))
  }, [items, session])
  const station = stations.find((entry) => entry.id === stationId)
  const { status } = useRestaurantRealtime({ outletId: station?.outlet_id, stationId, onUpdate: (event) => {
    if (event.type === 'order_sent') setNotice('New kitchen ticket received.')
    if (event.type === 'connection_restored') { void loadQueue(); return }
    setItems((current) => {
      const result = applyKitchenQueue(current, event, stationId)
      if (!result.handled) { void loadQueue(); return current }
      return result.items
    })
  } })
  useOperationalRefresh(loadQueue, status)
  const transition = async (item: KitchenItem, nextStatus: 'preparing' | 'ready') => {
    setBusyId(item.id); setError('')
    setItems((current) => current.map((entry) => entry.id === item.id ? { ...entry, status: nextStatus, ...(nextStatus === 'ready' ? { ready_at: new Date().toISOString() } : { preparing_at: new Date().toISOString() }) } : entry))
    try { const updated = unwrap(await api.post<ApiEnvelope<KitchenItem>>(`/api/v1/order-items/${item.id}/kitchen-status`, { status: nextStatus })); setItems((current) => current.map((entry) => entry.id === item.id ? { ...entry, ...updated } : entry)) } catch (requestError) { setError(errorMessage(requestError)); await loadQueue() } finally { setBusyId(null) }
  }
  const cancel = (item: KitchenItem) => { setCancelTarget(item) }
  const confirmKitchenCancel = async (reason: string) => {
    const item = cancelTarget
    if (!item) return
    setCancelTarget(null); setBusyId(item.id); setError('')
    try { await api.post(`/api/v1/order-items/${item.id}/cancel`, { reason }); setNotice(`${item.item_name} cancelled.`); setItems((current) => current.filter((entry) => entry.id !== item.id)) } catch (requestError) { setError(errorMessage(requestError)); await loadQueue() } finally { setBusyId(null) }
  }
  const [clock, setClock] = useState(() => Date.now())
  useEffect(() => { const timer = window.setInterval(() => setClock(Date.now()), 30000); return () => window.clearInterval(timer) }, [])
  const columns: Array<{ key: 'pending' | 'preparing' | 'ready'; label: string }> = [{ key: 'pending', label: 'New' }, { key: 'preparing', label: 'Preparing' }, { key: 'ready', label: 'Ready' }]
  const visible = filter === 'all' ? items : items.filter((item) => item.status === filter)
  return <section className="kitchen-page">
    <div className="page-heading"><div><p className="eyebrow">KITCHEN DISPLAY SYSTEM</p><h1>Kitchen queue</h1><p>Only items routed to this station appear here.</p></div><ConnectionState status={status} /></div>
    <div className="kitchen-toolbar"><div className="station-tabs">{stations.map((entry) => <button type="button" key={entry.id} onClick={() => setStationId(entry.id)} className={entry.id === stationId ? 'selected' : ''}>{entry.name}</button>)}</div><div className="status-filters"><button type="button" className={filter === 'all' ? 'selected' : ''} onClick={() => setFilter('all')}>All</button>{columns.map((column) => <button type="button" key={column.key} className={filter === column.key ? 'selected' : ''} onClick={() => setFilter(column.key)}>{column.label}</button>)}<button type="button" className="icon-refresh" onClick={() => void loadQueue()}><RefreshCw size={16} /></button></div></div>
    <div className="kitchen-columns">{columns.map((column) => { const columnItems = visible.filter((item) => item.status === column.key); return <section key={column.key}><h2>{column.label}<span>{columnItems.length}</span></h2><div className="kitchen-tickets">{groupKitchenTickets(columnItems).map((ticketItems) => <KitchenTicket key={`${column.key}-${ticketItems[0].id}`} items={ticketItems} now={clock} busyId={busyId} allowsCancel={(item) => canCancelItem(session, item)} onTransition={transition} onCancel={cancel} />)}</div></section> })}</div>
    <PromptDialog open={Boolean(cancelTarget)} title={`Cancel 1 × ${cancelTarget?.item_name ?? 'item'}`} description="Only this portion is cancelled. Other quantities of the same dish stay on the board." label="Required reason" type="textarea" minLength={3} confirmLabel="Cancel this portion" busy={busyId === cancelTarget?.id} onClose={() => setCancelTarget(null)} onConfirm={(reason) => void confirmKitchenCancel(reason)} />
    <Toast message={notice || error} tone={error ? 'error' : 'success'} onDismiss={() => { setNotice(''); setError('') }} />
  </section>
}

function KitchenTicket({ items, now, busyId, allowsCancel, onTransition, onCancel }: { items: KitchenItem[]; now: number; busyId: number | null; allowsCancel: (item: KitchenItem) => boolean; onTransition: (item: KitchenItem, status: 'preparing' | 'ready') => void; onCancel: (item: KitchenItem) => void }) {
  const first = items[0]
  const status = first.status
  const timestamps = items.map((item) => status === 'ready' ? item.ready_at : status === 'preparing' ? item.preparing_at : item.order?.sent_at).filter((timestamp): timestamp is string => Boolean(timestamp)).map((timestamp) => new Date(timestamp).getTime())
  const relevantTime = timestamps.length ? (status === 'ready' ? Math.max(...timestamps) : Math.min(...timestamps)) : null
  const minutes = relevantTime ? Math.max(0, Math.floor((now - relevantTime) / 60000)) : 0
  return <article className={`kitchen-ticket ${status !== 'ready' && minutes >= 15 ? 'delayed' : ''}`}><header><div><strong>{first.order?.dining_session?.dining_table?.name ?? 'Table'}</strong><small>{first.order?.ticket_number ?? 'Ticket'} · {first.order?.creator?.name ?? 'Waiter'} · {items.length} item{items.length > 1 ? 's' : ''}</small></div><span className="ticket-age"><Clock3 size={13} />{status === 'ready' ? `Ready ${minutes}m` : `${minutes}m`}</span></header><div className="kitchen-ticket-items">{items.map((item) => { const busy = busyId === item.id; const action = item.status === 'pending' ? ['Start prep', 'preparing'] : item.status === 'preparing' ? ['Mark ready', 'ready'] : null; return <section key={item.id} className="kitchen-ticket-item"><div className="ticket-item"><b>{item.quantity} ×</b><strong>{item.item_name}</strong></div>{item.kitchen_note && <p className="kitchen-note">{item.kitchen_note}</p>}<footer>{action ? <button type="button" className="button button-primary" disabled={busy} onClick={() => onTransition(item, action[1] as 'preparing' | 'ready')}><Check size={14} />{busy ? 'Updating…' : action[0]}</button> : <span className="ready-label"><Check size={14} />Ready for service</span>}{allowsCancel(item) && <button type="button" className="text-danger" disabled={busy} title={item.status === 'pending' ? 'Cancel this item' : 'Cancelling prepared food records wastage'} onClick={() => onCancel(item)}><X size={14} />Cancel</button>}</footer></section> })}</div></article>
}
