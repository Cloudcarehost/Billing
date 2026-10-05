import { Check, Clock3, RefreshCw, X } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { PromptDialog, Toast } from '../components/ui/Feedback'
import { useAuth } from '../features/auth/AuthContext'
import { canCancelItem } from '../features/auth/permissions'
import { useRestaurantRealtime } from '../features/realtime/useRestaurantRealtime'
import { api, errorMessage } from '../lib/api'
import type { ApiEnvelope, KitchenStation, LiveTicket, OrderItem } from '../types/api'
import { ConnectionState, elapsedHm, isDirectBillOutlet, unwrap, useElapsedClock, useOperationalRefresh } from './opsShared'
import { applyKitchenQueue } from '../features/realtime/applyRestaurantEvent'
import { playFloorSound, soundFor, unlockFloorAlert } from '../features/notifications/floorAlerts'
import { qtyLabel } from '../lib/billLines'

type KitchenItem = OrderItem & { order?: { id: number; ticket_number: string; round_number: number; sent_at?: string; creator?: { name: string }; dining_session?: { dining_table?: { name: string; code: string } } } }

function groupKitchenTickets(items: KitchenItem[]) {
  return items.map((item) => [item])
}

function asLiveTicket(value: unknown): LiveTicket | null {
  if (!value || typeof value !== 'object' || !('id' in value)) return null
  const ticket = value as LiveTicket
  return typeof ticket.id === 'number' ? ticket : null
}

export function KitchenPage() {
  const { session, activeOutletId } = useAuth()
  const directBill = isDirectBillOutlet(session?.outlets, activeOutletId)
  const [stations, setStations] = useState<KitchenStation[]>([])
  const [stationId, setStationId] = useState<number | null>(null)
  const [items, setItems] = useState<KitchenItem[]>([])
  const [filter, setFilter] = useState<'all' | 'pending' | 'preparing' | 'ready'>('all')
  const [tab, setTab] = useState<'queue' | 'done'>('queue')
  const [queue, setQueue] = useState<LiveTicket[]>([])
  const [done, setDone] = useState<LiveTicket[]>([])
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [busyId, setBusyId] = useState<number | null>(null)
  const [cancelTarget, setCancelTarget] = useState<KitchenItem | null>(null)
  const seenTicketIds = useRef<Set<number> | null>(null)
  const clock = useElapsedClock()
  useEffect(() => {
    const unlock = () => { void unlockFloorAlert() }
    window.addEventListener('pointerdown', unlock, { once: true })
    window.addEventListener('keydown', unlock, { once: true })
    return () => {
      window.removeEventListener('pointerdown', unlock)
      window.removeEventListener('keydown', unlock)
    }
  }, [])
  const loadStations = useCallback(async () => { if (directBill) { setStations([]); setStationId(null); setItems([]); return } try { const result = unwrap(await api.get<ApiEnvelope<KitchenStation[]>>('/api/v1/kitchen-stations', { params: { outlet_id: activeOutletId } })); setStations(result); setStationId((current) => current && result.some((station) => station.id === current) ? current : result[0]?.id ?? null) } catch (requestError) { setError(errorMessage(requestError)) } }, [activeOutletId, directBill])
  const loadQueue = useCallback(async () => { if (directBill || !stationId) { if (directBill) setItems([]); return } try { const result = unwrap(await api.get<ApiEnvelope<{ items: KitchenItem[] }>>(`/api/v1/kitchen-stations/${stationId}/queue`)); setItems(result.items) } catch (requestError) { setError(errorMessage(requestError)) } }, [stationId, directBill])
  const loadLiveBoard = useCallback(async () => {
    if (!directBill) { setQueue([]); setDone([]); return }
    try {
      const result = unwrap(await api.get<ApiEnvelope<{ queue: LiveTicket[]; done: LiveTicket[] }>>('/api/v1/live-board', { params: { outlet_id: activeOutletId } }))
      setQueue(result.queue)
      setDone(result.done)
    } catch (requestError) { setError(errorMessage(requestError)) }
  }, [directBill, activeOutletId])
  useEffect(() => { const task = window.setTimeout(() => void loadStations(), 0); return () => window.clearTimeout(task) }, [loadStations])
  useEffect(() => { const task = window.setTimeout(() => void loadQueue(), 0); return () => window.clearTimeout(task) }, [loadQueue])
  useEffect(() => { const task = window.setTimeout(() => void loadLiveBoard(), 0); return () => window.clearTimeout(task) }, [loadLiveBoard])
  useEffect(() => { seenTicketIds.current = null }, [stationId, directBill])
  useEffect(() => {
    if (seenTicketIds.current) {
      const arrived = items.filter((item) => item.status === 'pending' && !seenTicketIds.current!.has(item.id))
      if (arrived.length) playFloorSound(soundFor(session?.hotel, 'kitchen'))
    }
    seenTicketIds.current = new Set(items.map((item) => item.id))
  }, [items, session])
  const station = stations.find((entry) => entry.id === stationId)
  const { status } = useRestaurantRealtime({ outletId: station?.outlet_id ?? activeOutletId, stationId: directBill ? null : stationId, onUpdate: (event) => {
    if (event.type === 'outlet_flow_changed') {
      void loadStations(); void loadQueue(); void loadLiveBoard(); return
    }
    if (directBill) {
      if (event.type === 'connection_restored' || event.type === 'item_cancelled') { void loadLiveBoard(); return }
      if (event.type === 'table_closed' && event.session_id) {
        setQueue((current) => current.filter((ticket) => ticket.session_id !== event.session_id))
        setDone((current) => current.filter((ticket) => ticket.session_id !== event.session_id))
        return
      }
      if (event.type === 'order_sent') {
        const ticket = asLiveTicket(event.data?.live_ticket)
        if (!ticket) { void loadLiveBoard(); return }
        setQueue((current) => current.some((entry) => entry.id === ticket.id) ? current : [...current, ticket])
        setNotice('New order on the live board.')
        return
      }
      if (event.type === 'live_ticket_done') {
        const ticket = asLiveTicket(event.data?.live_ticket)
        if (!ticket) { void loadLiveBoard(); return }
        setQueue((current) => current.filter((entry) => entry.id !== ticket.id))
        setDone((current) => [ticket, ...current.filter((entry) => entry.id !== ticket.id)])
      }
      return
    }
    if (event.type === 'order_sent') setNotice('New kitchen ticket received.')
    if (event.type === 'connection_restored') { void loadQueue(); return }
    setItems((current) => {
      const result = applyKitchenQueue(current, event, stationId)
      if (!result.handled) { void loadQueue(); return current }
      return result.items
    })
  } })
  useOperationalRefresh(directBill ? loadLiveBoard : loadQueue, status)
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
  const markTrackedDone = async (ticket: LiveTicket) => {
    setBusyId(ticket.id); setError('')
    setQueue((current) => current.filter((entry) => entry.id !== ticket.id))
    setDone((current) => [{ ...ticket, tracked_done_at: new Date().toISOString() }, ...current.filter((entry) => entry.id !== ticket.id)])
    try { unwrap(await api.post<ApiEnvelope<LiveTicket>>(`/api/v1/orders/${ticket.id}/track-done`)); setNotice(`${ticket.table_name} marked done.`) } catch (requestError) { setError(errorMessage(requestError)); await loadLiveBoard() } finally { setBusyId(null) }
  }
  const columns: Array<{ key: 'pending' | 'preparing' | 'ready'; label: string }> = [{ key: 'pending', label: 'New' }, { key: 'preparing', label: 'Preparing' }, { key: 'ready', label: 'Ready' }]
  const visible = filter === 'all' ? items : items.filter((item) => item.status === filter)
  const board = tab === 'queue' ? queue : done
  return <section className="kitchen-page">
    <div className="page-heading"><div><p className="eyebrow">KITCHEN DISPLAY SYSTEM</p><h1>{directBill ? 'Live board' : 'Kitchen queue'}</h1><p>{directBill ? 'Track new rounds here. Billing and item status stay unchanged.' : 'Only items routed to this station appear here.'}</p></div><ConnectionState status={status} /></div>
    {directBill ? <>
      <div className="kitchen-toolbar"><div className="station-tabs"><button type="button" className={tab === 'queue' ? 'selected' : ''} onClick={() => setTab('queue')}>In queue<span>{queue.length}</span></button><button type="button" className={tab === 'done' ? 'selected' : ''} onClick={() => setTab('done')}>Done<span>{done.length}</span></button></div><button type="button" className="icon-refresh" onClick={() => void loadLiveBoard()}><RefreshCw size={16} /></button></div>
      <div className="live-board">{!board.length ? <div className="empty-state"><h2>{tab === 'queue' ? 'Nothing in queue' : 'Nothing done yet'}</h2><p>{tab === 'queue' ? 'New orders appear at the bottom as soon as they are added to a bill.' : 'Tickets marked done show here for 3 hours, or until the bill is paid.'}</p></div> : board.map((ticket) => <article className={`kitchen-ticket ${tab === 'done' ? 'live-ticket-done' : ''}`} key={ticket.id}><header><div><strong>{ticket.table_name}</strong><small>{ticket.ticket_number}{ticket.creator_name ? ` · ${ticket.creator_name}` : ''} · {ticket.items.length} item{ticket.items.length > 1 ? 's' : ''}</small></div><span className="ticket-age"><Clock3 size={13} />{elapsedHm(ticket.sent_at, clock) ?? '0h 00m'}</span></header><div className="kitchen-ticket-items">{ticket.items.map((item) => <section key={item.id} className="kitchen-ticket-item"><div className="ticket-item"><b>{qtyLabel(Number(item.quantity))} ×</b><strong>{item.item_name}</strong></div></section>)}</div>{tab === 'queue' && <footer className="live-ticket-actions"><button type="button" className="button button-primary" disabled={busyId === ticket.id} onClick={() => void markTrackedDone(ticket)}><Check size={14} />{busyId === ticket.id ? 'Saving…' : 'Done'}</button></footer>}</article>)}</div>
    </> : <>
    <div className="kitchen-toolbar"><div className="station-tabs">{stations.map((entry) => <button type="button" key={entry.id} onClick={() => setStationId(entry.id)} className={entry.id === stationId ? 'selected' : ''}>{entry.name}</button>)}</div><div className="status-filters"><button type="button" className={filter === 'all' ? 'selected' : ''} onClick={() => setFilter('all')}>All</button>{columns.map((column) => <button type="button" key={column.key} className={filter === column.key ? 'selected' : ''} onClick={() => setFilter(column.key)}>{column.label}</button>)}<button type="button" className="icon-refresh" onClick={() => void loadQueue()}><RefreshCw size={16} /></button></div></div>
    <div className="kitchen-columns">{columns.map((column) => { const columnItems = visible.filter((item) => item.status === column.key); return <section key={column.key}><h2>{column.label}<span>{columnItems.length}</span></h2><div className="kitchen-tickets">{groupKitchenTickets(columnItems).map((ticketItems) => <KitchenTicket key={`${column.key}-${ticketItems[0].id}`} items={ticketItems} now={clock} busyId={busyId} allowsCancel={(item) => canCancelItem(session, item)} onTransition={transition} onCancel={cancel} />)}</div></section> })}</div>
    </>}
    <PromptDialog open={Boolean(cancelTarget)} title={`Cancel 1 × ${cancelTarget?.item_name ?? 'item'}`} description="Only this portion is cancelled. Other quantities of the same dish stay on the board." label="Required reason" type="textarea" minLength={3} confirmLabel="Cancel this portion" busy={busyId === cancelTarget?.id} onClose={() => setCancelTarget(null)} onConfirm={(reason) => void confirmKitchenCancel(reason)} />
    <Toast message={error || notice} tone={error ? 'error' : 'success'} onDismiss={() => { setNotice(''); setError('') }} />
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
