import { BellRing, Check, ChefHat, Minus, Package, Pencil, Pin, Plus, ReceiptText, Search, Send, TabletSmartphone, X } from 'lucide-react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { ConfirmDialog, Modal, PromptDialog, Toast } from '../components/ui/Feedback'
import { useAuth } from '../features/auth/AuthContext'
import { can, canCancelItem } from '../features/auth/permissions'
import { idempotencyHeaders, useRestaurantRealtime } from '../features/realtime/useRestaurantRealtime'
import { api, errorMessage } from '../lib/api'
import type { ApiEnvelope, DiningSession, DiningTable, OrderItem, Outlet, PopularProduct, Product } from '../types/api'
import { acknowledgeWaiterCall, ConnectionState, isDirectBillOutlet, isParcelTable, money, readable, readyToServeCount, TableAlertMarks, unwrap, useOperationalRefresh, value } from './opsShared'
import { applyTableEvent, tableWithSession } from '../features/realtime/applyRestaurantEvent'
import { useReadyNotifications } from '../features/notifications/ReadyNotificationContext'

export function OrdersPage() {
  const { session, activeOutletId } = useAuth()
  const { ingestFloorTables } = useReadyNotifications()
  const canOpenSession = can(session, 'sessions.open')
  const [params, setParams] = useSearchParams()
  const [tables, setTables] = useState<DiningTable[]>([])
  const { data: products = [] } = useQuery({ queryKey: ['order-products', activeOutletId], queryFn: async () => unwrap(await api.get<ApiEnvelope<Product[]>>('/api/v1/products/search', { params: { active: 1, limit: 500 } })), staleTime: 5 * 60_000 })
  const { data: popular = [] } = useQuery({ queryKey: ['order-popular', activeOutletId], queryFn: async () => activeOutletId ? unwrap(await api.get<ApiEnvelope<PopularProduct[]>>('/api/v1/products/popular', { params: { outlet_id: activeOutletId, days: 14, limit: 8 } })) : [], enabled: Boolean(activeOutletId), staleTime: 5 * 60_000, placeholderData: [] })
  const [selectedTableId, setSelectedTableId] = useState<number | null>(null)
  const [search, setSearch] = useState(params.get('q') ?? '')
  const [category, setCategory] = useState<number | 'all'>('all')
  const [cart, setCart] = useState<Record<number, { product: Product; quantity: number; note: string }>>({})
  const [busy, setBusy] = useState(false)
  const [servingItemId, setServingItemId] = useState<number | null>(null)
  const [cancellingItemId, setCancellingItemId] = useState<number | null>(null)
  const [guestPromptOpen, setGuestPromptOpen] = useState(false)
  const [cancelTarget, setCancelTarget] = useState<OrderItem | null>(null)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const requestedTableId = Number(params.get('table')) || null
  const chooseTable = (tableId: number) => {
    setSelectedTableId(tableId)
    setParams({ table: String(tableId) }, { replace: true })
    setTables((current) => current.map((entry) => entry.id === tableId && entry.waiter_called ? { ...entry, waiter_called: false } : entry))
    void acknowledgeWaiterCall(tableId).catch(() => undefined)
  }
  const outletId = tables.find((table) => table.id === selectedTableId)?.outlet_id ?? tables[0]?.outlet_id
  const load = useCallback(async () => {
    try {
      const tableResponse = await api.get<ApiEnvelope<DiningTable[]>>('/api/v1/tables/status', { params: { outlet_id: activeOutletId } })
      const nextTables = unwrap(tableResponse); setTables(nextTables)
      setSelectedTableId((current) => requestedTableId && nextTables.some((table) => table.id === requestedTableId) ? requestedTableId : current && nextTables.some((table) => table.id === current) ? current : (nextTables.find((table) => table.active_session)?.id ?? nextTables[0]?.id ?? null))
    } catch (requestError) { setError(errorMessage(requestError)) }
  }, [requestedTableId, activeOutletId])
  const refreshOperationalState = useCallback(async () => {
    try {
      const nextTables = unwrap(await api.get<ApiEnvelope<DiningTable[]>>('/api/v1/tables/status', { params: { outlet_id: activeOutletId } }))
      setTables(nextTables)
      setSelectedTableId((current) => requestedTableId && nextTables.some((entry) => entry.id === requestedTableId) ? requestedTableId : current && nextTables.some((entry) => entry.id === current) ? current : nextTables[0]?.id ?? null)
    } catch (requestError) { setError(errorMessage(requestError)) }
  }, [requestedTableId, activeOutletId])
  const loadSelectedSession = useCallback(async (sessionId: number) => {
    try {
      const updated = unwrap(await api.get<ApiEnvelope<DiningSession>>(`/api/v1/dining-sessions/${sessionId}`))
      setTables((current) => current.map((entry) => entry.active_session?.id === sessionId ? tableWithSession(entry, updated) : entry))
    } catch (requestError) { setError(errorMessage(requestError)) }
  }, [])
  useEffect(() => { const task = window.setTimeout(() => void load(), 0); return () => window.clearTimeout(task) }, [load])
  const selectedSessionId = tables.find((entry) => entry.id === selectedTableId)?.active_session?.id
  useEffect(() => { if (!selectedSessionId) return; const task = window.setTimeout(() => { void loadSelectedSession(selectedSessionId) }, 0); return () => window.clearTimeout(task) }, [selectedSessionId, loadSelectedSession])
  const { status } = useRestaurantRealtime({ outletId: activeOutletId ?? outletId, tableId: selectedTableId, onUpdate: (event) => {
    if (event.type === 'connection_restored') { void refreshOperationalState(); return }
    setTables((current) => {
      const result = applyTableEvent(current, event)
      if (!result.handled) { void refreshOperationalState(); return current }
      if (result.needsSession && result.needsSession === selectedSessionId) void loadSelectedSession(result.needsSession)
      return result.tables
    })
  } })
  useOperationalRefresh(refreshOperationalState, status)
  useEffect(() => { ingestFloorTables(tables) }, [tables, ingestFloorTables])
  const table = tables.find((entry) => entry.id === selectedTableId)
  const active = table?.active_session
  const categories = useMemo(() => Array.from(new Map(products.filter((product) => product.category).map((product) => [product.category!.id, product.category!])).values()), [products])
  const popularRank = useMemo(() => {
    const ranks = new Map<number, number>()
    popular.forEach((entry, index) => ranks.set(entry.product_id, index))
    return ranks
  }, [popular])
  const quickProducts = useMemo(() => {
    const seen = new Set<number>()
    const list: Product[] = []
    for (const product of [...products.filter((item) => item.is_active && item.is_quick)].sort((a, b) => a.name.localeCompare(b.name))) {
      if (seen.has(product.id) || list.length >= 12) continue
      seen.add(product.id)
      list.push(product)
    }
    for (const row of popular) {
      if (list.length >= 12) break
      const product = products.find((item) => item.id === row.product_id && item.is_active)
      if (!product || seen.has(product.id)) continue
      seen.add(product.id)
      list.push(product)
    }
    return list
  }, [products, popular])
  const filteredProducts = useMemo(() => {
    const term = search.toLowerCase()
    const list = products.filter((product) => product.is_active && (category === 'all' || product.category_id === category) && `${product.name} ${product.sku ?? ''} ${product.barcode ?? ''}`.toLowerCase().includes(term))
    return [...list].sort((a, b) => {
      const aCart = cart[a.id] ? 0 : 1
      const bCart = cart[b.id] ? 0 : 1
      if (aCart !== bCart) return aCart - bCart
      if (category !== 'all' || term) return a.name.localeCompare(b.name)
      const aPin = a.is_quick ? 0 : 1
      const bPin = b.is_quick ? 0 : 1
      if (aPin !== bPin) return aPin - bPin
      const aPop = popularRank.get(a.id) ?? 999
      const bPop = popularRank.get(b.id) ?? 999
      if (aPop !== bPop) return aPop - bPop
      return a.name.localeCompare(b.name)
    })
  }, [products, category, search, popularRank, cart])
  const cartItems = Object.values(cart)
  const cartTotal = cartItems.reduce((total, entry) => total + value(entry.product.selling_price) * entry.quantity, 0)
  const directBill = isDirectBillOutlet(session?.outlets, activeOutletId ?? table?.outlet_id)
  const readyItems = directBill ? 0 : (active?.orders?.flatMap((order) => order.items).filter((item) => item.status === 'ready').length ?? active?.kitchen_progress?.ready ?? 0)

  const openTable = () => {
    if (!table || table.active_session || !table.is_active) return
    setGuestPromptOpen(true)
  }
  const confirmGuests = async (value: string) => {
    if (!table) return
    const guestCount = Math.max(1, Math.floor(Number(value) || 1))
    setBusy(true); setError(''); setGuestPromptOpen(false)
    try { const created = unwrap(await api.post<ApiEnvelope<DiningSession>>(`/api/v1/tables/${table.id}/sessions`, { guest_count: guestCount })); setTables((current) => current.map((entry) => entry.id === table.id ? tableWithSession(entry, created) : entry)); setNotice(`${table.name} is ready for the order.`) } catch (requestError) { setError(errorMessage(requestError)); await load() } finally { setBusy(false) }
  }
  const changeCart = (product: Product, change: number) => setCart((current) => {
    const item = current[product.id]; const quantity = (item?.quantity ?? 0) + change
    if (quantity <= 0) { const rest = { ...current }; delete rest[product.id]; return rest }
    return { ...current, [product.id]: { product, quantity, note: item?.note ?? '' } }
  })
  const noteCart = (productId: number, note: string) => setCart((current) => ({ ...current, [productId]: { ...current[productId], note } }))
  const sendOrder = async () => {
    if (!active || !cartItems.length || busy) return
    setBusy(true); setError('')
    try {
      const updated = unwrap(await api.post<ApiEnvelope<DiningSession>>(`/api/v1/dining-sessions/${active.id}/orders`, { items: cartItems.map(({ product, quantity, note }) => ({ product_id: product.id, quantity, kitchen_note: note || null })) }, { headers: idempotencyHeaders() }))
      setCart({}); setTables((current) => current.map((entry) => entry.id === table.id ? tableWithSession(entry, updated) : entry)); setNotice(directBill ? 'Items added to the bill.' : 'Order sent to the kitchen.')
    } catch (requestError) { setError(errorMessage(requestError)) } finally { setBusy(false) }
  }
  const requestBill = async () => {
    if (!active || busy) return
    setBusy(true); setError('')
    try { const updated = unwrap(await api.post<ApiEnvelope<DiningSession>>(`/api/v1/dining-sessions/${active.id}/request-bill`)); setTables((current) => current.map((entry) => entry.id === table.id ? tableWithSession(entry, updated) : entry)); setNotice('Bill request sent to the counter.') } catch (requestError) { setError(errorMessage(requestError)); await load() } finally { setBusy(false) }
  }
  const serveItem = async (item: OrderItem) => {
    if (item.status !== 'ready' || servingItemId) return
    setServingItemId(item.id); setError('')
    setTables((current) => current.map((currentTable) => !currentTable.active_session?.orders ? currentTable : ({ ...currentTable, active_session: { ...currentTable.active_session, orders: currentTable.active_session.orders.map((order) => ({ ...order, items: order.items.map((orderItem) => orderItem.id === item.id ? { ...orderItem, status: 'served' } : orderItem) })) } })))
    try { await api.post(`/api/v1/order-items/${item.id}/serve`); setNotice(`${item.item_name} marked as served.`) } catch (requestError) { setError(errorMessage(requestError)); await load() } finally { setServingItemId(null) }
  }
  const cancelPrepItem = (item: OrderItem) => {
    if (!canCancelItem(session, item) || cancellingItemId) return
    setCancelTarget(item)
  }
  const confirmCancelPrep = async (reason: string) => {
    const item = cancelTarget
    if (!item) return
    setCancelTarget(null); setCancellingItemId(item.id); setError('')
    try { await api.post(`/api/v1/order-items/${item.id}/cancel`, { reason }); setNotice(`${item.item_name} cancelled.`); setTables((current) => current.map((entry) => !entry.active_session?.orders ? entry : ({ ...entry, active_session: { ...entry.active_session, orders: entry.active_session.orders.map((order) => ({ ...order, items: order.items.map((orderItem) => orderItem.id === item.id ? { ...orderItem, status: 'cancelled' } : orderItem) })) } }))) } catch (requestError) { setError(errorMessage(requestError)); await load() } finally { setCancellingItemId(null) }
  }
  const startParcel = async () => {
    if (busy || !canOpenSession) return
    setBusy(true); setError('')
    try {
      const created = unwrap(await api.post<ApiEnvelope<DiningTable>>('/api/v1/parcels', { outlet_id: activeOutletId }))
      setTables((current) => current.some((entry) => entry.id === created.id) ? current.map((entry) => entry.id === created.id ? created : entry) : [...current, created])
      chooseTable(created.id)
      setNotice(`${created.name} is ready. Add items and send them like a table order.`)
    } catch (requestError) { setError(errorMessage(requestError)); await load() } finally { setBusy(false) }
  }

  return <section className="waiter-page">
    <div className="page-heading"><div><p className="eyebrow">WAITER MODE</p><h1>Take an order</h1><p>Fast, touch-friendly ordering for the dining floor.</p></div><ConnectionState status={status} /></div>
    <div className="waiter-layout">
      <aside className="waiter-tables"><h2>Choose table</h2>{canOpenSession && <button type="button" className="waiter-table new-parcel-table" disabled={busy} onClick={() => void startParcel()}><strong>New parcel</strong><span>Takeaway for counter or waiter</span></button>}{tables.filter((entry) => !isParcelTable(entry) || entry.active_session || entry.id === selectedTableId).map((entry) => { const ready = readyToServeCount(entry, isDirectBillOutlet(session?.outlets, entry.outlet_id)); const called = Boolean(entry.waiter_called); return <button key={entry.id} type="button" className={`waiter-table ${entry.id === table?.id ? 'active' : ''} ${entry.display_status}${ready ? ' needs-serve' : ''}${called ? ' needs-waiter' : ''}`} onClick={() => chooseTable(entry.id)}><strong>{entry.name}<TableAlertMarks ready={ready} waiterCalled={called} /></strong><span>{entry.active_session ? `${isParcelTable(entry) ? 'Parcel' : `${entry.active_session.guest_count} guests`} · ${readable(entry.display_status)}${called ? ' · guest called' : ''}${ready ? ' · serve now' : ''}` : entry.is_active ? 'Available' : 'Unavailable'}</span><b>{money(entry.current_total, session?.hotel.currency_code)}</b></button> })}</aside>
      <main className="waiter-workspace">
        {!table ? <div className="empty-state"><TabletSmartphone size={34} /><h2>Select a table</h2><p>Choose a table to start a new order or continue a current one.</p></div> : !active ? <div className="empty-state"><ChefHat size={34} /><h2>{table.name} is available</h2><p>Start a session with the guest count before adding items.</p>{canOpenSession && <button type="button" className="button button-primary" disabled={!table.is_active || busy} onClick={() => void openTable()}><Plus size={16} />Assign table</button>}</div> : <>
          <div className="waiter-session-bar"><div><span className={`table-state ${table.display_status}`}>{isParcelTable(table) ? 'Parcel' : readable(table.display_status)}</span><h2>{table.name} <small>{isParcelTable(table) ? 'Takeaway' : `${active.guest_count} guests`} · {active.waiter?.name ?? 'Current waiter'}</small></h2>{active.orders.length > 0 && <p className="additional-order-label">{directBill ? 'Additional items join this table bill' : 'Additional order — items below join this table as a new kitchen ticket'}</p>}</div>{readyItems > 0 && <span className="ready-alert"><BellRing size={15} />{readyItems} item{readyItems > 1 ? 's' : ''} ready</span>}{can(session, 'billing.request') && <button type="button" className="button button-secondary waiter-request-bill-desktop" disabled={busy || active.status !== 'occupied'} onClick={() => void requestBill()}><ReceiptText size={15} />Request bill</button>}</div>
          <div className="waiter-search"><Search size={17} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search menu, SKU or barcode" inputMode="search" />{search ? <button type="button" className="search-clear" aria-label="Clear search" onClick={() => setSearch('')}><X size={16} /></button> : null}</div>
          {quickProducts.length > 0 && <div className="quick-order-strip" aria-label="Quick add"><span>Quick</span><div>{quickProducts.map((product) => { const item = cart[product.id]; return <button type="button" key={product.id} className={`quick-chip${item ? ' in-cart' : ''}${product.is_quick ? ' pinned' : ''}`} onClick={() => changeCart(product, 1)}><strong>{product.is_quick ? <Pin size={12} /> : null}{product.name}</strong>{item ? <b>{item.quantity}</b> : null}</button> })}</div></div>}
          <div className="category-tabs" role="tablist" aria-label="Menu categories"><button type="button" className={category === 'all' ? 'selected' : ''} onClick={() => setCategory('all')}>All menu</button>{categories.map((entry) => <button type="button" key={entry.id} className={category === entry.id ? 'selected' : ''} onClick={() => setCategory((current) => current === entry.id ? 'all' : entry.id)}>{entry.name}</button>)}</div>
          <div className="waiter-order-grid"><div className="touch-product-grid">{filteredProducts.map((product) => { const item = cart[product.id]; return <article className={`touch-product${item ? ' in-cart' : ''}`} key={product.id}><div><strong>{product.name}</strong>{product.serving_size && <small className="serving-size">{product.serving_size}</small>}{product.short_description && <p className="product-blurb">{product.short_description}</p>}<small>{product.category?.name ?? 'Menu item'}{directBill ? '' : ` · ${product.fulfillment_mode === 'direct' ? 'Direct' : 'Kitchen'}`}</small><b>{money(product.selling_price, session?.hotel.currency_code)}</b></div>{item ? <div className="cart-product-controls"><div className="quantity-control"><button type="button" aria-label={`Decrease ${product.name}`} onClick={() => changeCart(product, -1)}><Minus size={16} /></button><span>{item.quantity}</span><button type="button" aria-label={`Increase ${product.name}`} onClick={() => changeCart(product, 1)}><Plus size={16} /></button></div>{!directBill && <input value={item.note} onChange={(event) => noteCart(product.id, event.target.value)} placeholder="Kitchen note" />}</div> : <button type="button" className="button button-secondary button-touch" onClick={() => changeCart(product, 1)}><Plus size={16} />Add</button>}</article> })}</div>
            <aside className="order-cart"><h2>Current order <span>{cartItems.length}</span></h2>{cartItems.length ? <><div className="cart-lines">{cartItems.map(({ product, quantity, note }) => <div key={product.id}><strong>{product.name}{product.serving_size ? ` · ${product.serving_size}` : ''}</strong><span>{quantity} × {money(product.selling_price, session?.hotel.currency_code)}</span>{note && <small>{note}</small>}</div>)}</div><div className="cart-total"><span>Estimated total</span><strong>{money(cartTotal, session?.hotel.currency_code)}</strong></div><button type="button" className="button button-primary button-full button-touch" disabled={busy || active.status !== 'occupied'} onClick={() => void sendOrder()}><Send size={15} />{busy ? (directBill ? 'Adding…' : 'Sending…') : (directBill ? 'Add to bill' : 'Send to kitchen')}</button></> : <p className="cart-empty">{directBill ? 'Add menu items here. Each send adds them to this table bill.' : 'Add menu items here. Each send creates a new kitchen ticket.'}</p>}</aside>
          </div>
          <section className="order-status-history"><h2>{directBill ? 'Bill items' : 'Preparation status'}</h2>{active.orders.length ? active.orders.map((order) => <article key={order.id}><header><strong>{order.ticket_number}</strong><span>Round {order.round_number}</span></header>{order.items.map((item) => <div className={`status-line ${!directBill && item.status === 'ready' ? 'ready-to-serve' : ''}`} key={item.id}><span>{item.quantity} × {item.item_name}{!directBill && <small>{item.fulfillment_mode === 'direct' ? 'Direct service' : 'Kitchen item'}</small>}</span>{!directBill && <b className={`item-status ${item.status}`}>{readable(item.status)}</b>}{!directBill && item.status === 'ready' && can(session, 'orders.create') && <button type="button" className="button button-primary serve-item-button" disabled={servingItemId === item.id} onClick={() => void serveItem(item)}><Check size={17} />{servingItemId === item.id ? 'Serving…' : 'Served'}</button>}{canCancelItem(session, item) && active.status === 'occupied' && <button type="button" className="text-danger" disabled={cancellingItemId === item.id} onClick={() => void cancelPrepItem(item)}><X size={14} />{cancellingItemId === item.id ? 'Cancelling…' : 'Cancel'}</button>}</div>)}</article>) : <p>{directBill ? 'No items have been added for this table.' : 'No orders have been sent for this table.'}</p>}</section>
        </>}
      </main>
    </div>
    {active && table && <div className="waiter-sticky-actions">{cartItems.length > 0 && <div className="waiter-sticky-cart" aria-label="Current order">{cartItems.map(({ product, quantity }) => <div key={product.id}><strong>{product.name}{product.serving_size ? ` · ${product.serving_size}` : ''}</strong><span>{quantity} × {money(product.selling_price, session?.hotel.currency_code)}</span></div>)}</div>}<div className="waiter-sticky-meta"><strong>{table.name}</strong><span>{active.orders.length ? 'Additional order' : 'First order'} · {cartItems.reduce((sum, item) => sum + item.quantity, 0)} items · {money(cartTotal, session?.hotel.currency_code)}</span></div><div className="waiter-sticky-buttons">{can(session, 'billing.request') && <button type="button" className="button button-secondary button-touch" disabled={busy || active.status !== 'occupied'} onClick={() => void requestBill()}><ReceiptText size={16} />Request bill</button>}<button type="button" className="button button-primary button-touch" disabled={busy || active.status !== 'occupied' || !cartItems.length} onClick={() => void sendOrder()}><Send size={16} />{busy ? (directBill ? 'Adding…' : 'Sending…') : (directBill ? 'Add to bill' : 'Send order')}</button></div></div>}
    <PromptDialog open={guestPromptOpen} title={`Open ${table?.name ?? 'table'}`} description="Guest count is required so the floor knows how many people are seated." label="Number of guests" type="number" defaultValue="1" min={1} step={1} confirmLabel="Start order" busy={busy} onClose={() => setGuestPromptOpen(false)} onConfirm={(value) => void confirmGuests(value)} />
    <PromptDialog open={Boolean(cancelTarget)} title={`Cancel ${cancelTarget?.quantity ?? 1} × ${cancelTarget?.item_name ?? 'item'}`} description="Only this portion is cancelled. Other quantities of the same dish stay on the table." label="Required reason" type="textarea" minLength={3} confirmLabel="Cancel this portion" busy={Boolean(cancellingItemId)} onClose={() => setCancelTarget(null)} onConfirm={(reason) => void confirmCancelPrep(reason)} />
    <Toast message={notice || error} tone={error ? 'error' : 'success'} onDismiss={() => { setNotice(''); setError('') }} />
  </section>
}

export function TablesPage() {
  const { session, activeOutletId } = useAuth()
  const { ingestFloorTables } = useReadyNotifications()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const manageTables = can(session, 'tables.configure')
  const canAssignWaiter = can(session, 'sessions.assign')
  const [tables, setTables] = useState<DiningTable[]>([])
  const [outlets, setOutlets] = useState<Outlet[]>([])
  const [staff, setStaff] = useState<Array<{ id: number; name: string }>>([])
  const [selected, setSelected] = useState<DiningTable | null>(null)
  const [tableFormOpen, setTableFormOpen] = useState(false)
  const [editingTable, setEditingTable] = useState<DiningTable | null>(null)
  const [assignWaiterId, setAssignWaiterId] = useState('')
  const [savingTable, setSavingTable] = useState(false)
  const [billRequestTable, setBillRequestTable] = useState<DiningTable | null>(null)
  const [requestingBillId, setRequestingBillId] = useState<number | null>(null)
  const [search, setSearch] = useState(params.get('q') ?? '')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [cancelTarget, setCancelTarget] = useState<OrderItem | null>(null)
  const [startingParcel, setStartingParcel] = useState(false)
  const load = useCallback(async () => {
    try {
      const [tableResponse, outletResponse, staffResponse] = await Promise.all([
        api.get<ApiEnvelope<DiningTable[]>>('/api/v1/tables/status', { params: { outlet_id: activeOutletId } }),
        manageTables ? api.get<ApiEnvelope<Outlet[]>>('/api/v1/outlets') : Promise.resolve(null),
        canAssignWaiter ? api.get<ApiEnvelope<Array<{ id: number; name: string }>>>('/api/v1/dining-staff') : Promise.resolve(null),
      ])
      setTables(unwrap(tableResponse))
      if (outletResponse) setOutlets(unwrap(outletResponse))
      if (staffResponse) setStaff(unwrap(staffResponse))
    } catch (requestError) { setError(errorMessage(requestError)) }
  }, [manageTables, canAssignWaiter, activeOutletId])
  useEffect(() => { const task = window.setTimeout(() => void load(), 0); return () => window.clearTimeout(task) }, [load])
  const selectedSessionId = selected?.active_session?.id
  const selectedHasHistory = Boolean(selected?.active_session?.status_history)
  useEffect(() => {
    if (!selectedSessionId || selectedHasHistory) return
    let cancelled = false
    void api.get<ApiEnvelope<DiningSession>>(`/api/v1/dining-sessions/${selectedSessionId}`).then((response) => {
      if (!cancelled) setSelected((current) => current?.active_session?.id === selectedSessionId ? { ...current, active_session: unwrap(response) } : current)
    }).catch((requestError) => { if (!cancelled) setError(errorMessage(requestError)) })
    return () => { cancelled = true }
  }, [selectedSessionId, selectedHasHistory])
  const outletId = activeOutletId ?? tables[0]?.outlet_id
  const { status } = useRestaurantRealtime({ outletId, onUpdate: (event) => {
    if (event.type === 'connection_restored') { void load(); return }
    setTables((current) => {
      const result = applyTableEvent(current, event)
      if (!result.handled) { void load(); return current }
      return result.tables
    })
    setSelected((current) => {
      if (!current || current.id !== event.table_id) return current
      if (event.type === 'table_closed') return null
      const result = applyTableEvent([current], event)
      return result.tables[0] ?? current
    })
  } })
  useOperationalRefresh(load, status)
  useEffect(() => { ingestFloorTables(tables) }, [tables, ingestFloorTables])
  const filtered = tables.filter((table) => `${table.name} ${table.code} ${table.active_session?.orders.flatMap((order) => order.items).map((item) => item.item_name).join(' ') ?? ''}`.toLowerCase().includes(search.toLowerCase()))
  const dineInTables = tables.filter((table) => !isParcelTable(table))
  const dineInFiltered = filtered.filter((table) => !isParcelTable(table))
  const parcelFiltered = filtered.filter((table) => isParcelTable(table) && table.active_session)
  const metrics = { total: dineInTables.length, occupied: dineInTables.filter((table) => table.display_status !== 'available').length, pending: tables.filter((table) => table.display_status === 'pending_bill').length, available: dineInTables.filter((table) => table.display_status === 'available').length }
  const cancelItem = (item: OrderItem) => { if (!canCancelItem(session, item)) return; setCancelTarget(item) }
  const confirmTableCancel = async (reason: string) => {
    const item = cancelTarget
    if (!item) return
    setCancelTarget(null)
    try { await api.post(`/api/v1/order-items/${item.id}/cancel`, { reason }); await load(); setNotice('Item cancelled and retained in the audit history.') } catch (requestError) { setError(errorMessage(requestError)) }
  }
  const assignWaiter = async () => {
    if (!selected?.active_session || !assignWaiterId) return
    setSavingTable(true); setError('')
    try {
      const updated = unwrap(await api.post<ApiEnvelope<DiningSession>>(`/api/v1/dining-sessions/${selected.active_session.id}/assign-waiter`, { waiter_id: Number(assignWaiterId) }))
      setSelected((current) => current ? tableWithSession(current, updated) : current)
      setNotice(`Assigned to ${updated.waiter?.name ?? 'the selected waiter'}.`)
    } catch (requestError) { setError(errorMessage(requestError)) } finally { setSavingTable(false) }
  }
  const requestBill = async () => {
    const table = billRequestTable
    if (!table?.active_session || requestingBillId) return
    setRequestingBillId(table.active_session.id); setBillRequestTable(null); setError('')
    setTables((current) => current.map((entry) => entry.id === table.id && entry.active_session ? { ...entry, display_status: 'pending_bill', active_session: { ...entry.active_session, status: 'pending_bill' } } : entry))
    try { const updated = unwrap(await api.post<ApiEnvelope<DiningSession>>(`/api/v1/dining-sessions/${table.active_session.id}/request-bill`)); setTables((current) => current.map((entry) => entry.id === table.id ? tableWithSession(entry, updated) : entry)); setNotice(`Bill requested for ${table.name}. The counter has been notified.`) } catch (requestError) { setError(errorMessage(requestError)); await load() } finally { setRequestingBillId(null) }
  }
  const saveTable = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const values = new FormData(event.currentTarget)
    setSavingTable(true); setError('')
    try {
      if (editingTable) {
        await api.patch(`/api/v1/tables/${editingTable.id}`, { name: String(values.get('name')), code: String(values.get('code')), capacity: Number(values.get('capacity')), is_active: values.get('is_active') === '1' })
        setNotice('Table details updated.')
      } else {
        await api.post('/api/v1/tables', { outlet_id: Number(values.get('outlet_id')), name: String(values.get('name')), code: String(values.get('code')), capacity: Number(values.get('capacity')), is_active: true })
        setNotice('Table created and ready to use.')
      }
      setTableFormOpen(false)
      setEditingTable(null)
      await load()
    } catch (requestError) { setError(errorMessage(requestError)) } finally { setSavingTable(false) }
  }
  const openTableForm = (table?: DiningTable) => { setEditingTable(table ?? null); setTableFormOpen(true) }
  const closeTableForm = () => { if (savingTable) return; setTableFormOpen(false); setEditingTable(null) }
  const startParcel = async () => {
    if (startingParcel || !can(session, 'sessions.open')) return
    setStartingParcel(true); setError('')
    try {
      const created = unwrap(await api.post<ApiEnvelope<DiningTable>>('/api/v1/parcels', { outlet_id: activeOutletId }))
      await load()
      navigate(`/app/orders?table=${created.id}`)
    } catch (requestError) { setError(errorMessage(requestError)) } finally { setStartingParcel(false) }
  }
  const renderFloorCard = (table: DiningTable) => {
    const ready = readyToServeCount(table, isDirectBillOutlet(session?.outlets, table.outlet_id))
    const called = Boolean(table.waiter_called)
    const clearCall = () => { setTables((current) => current.map((entry) => entry.id === table.id ? { ...entry, waiter_called: false } : entry)); void acknowledgeWaiterCall(table.id).catch(() => undefined) }
    const hideKitchenStatus = isDirectBillOutlet(session?.outlets, table.outlet_id)
    const parcel = isParcelTable(table)
    return <article key={table.id} className={`floor-card ${table.display_status} ${table.display_status === 'pending_bill' ? 'bill-highlight' : ''}${ready ? ' needs-serve' : ''}${called ? ' needs-waiter' : ''}`}>
      <div className="floor-card-heading"><div><span className={`table-state ${table.display_status}`}>{parcel ? 'Parcel' : readable(table.display_status)}</span><h2>{table.name}<TableAlertMarks ready={ready} waiterCalled={called} /></h2><p>{table.active_session ? `${parcel ? 'Takeaway' : `${table.active_session.guest_count} guests`} · ${table.active_session.waiter?.name ?? 'No waiter'}${called ? ' · guest called waiter' : ''}${ready ? ' · food ready to serve' : ''}` : table.is_active ? `${table.capacity} seats` : 'Unavailable'}</p></div>{parcel ? <Package size={27} /> : <ChefHat size={27} />}</div>
      {table.active_session ? <><div className="table-order-preview">{table.active_session.orders.flatMap((order) => order.items).filter((item) => item.status !== 'cancelled').slice(0, 4).map((item) => <span key={item.id}>{item.item_name}<b>{item.quantity}{hideKitchenStatus ? '' : ` · ${readable(item.status)}`}</b></span>)}</div><div className="table-total"><span>Current bill</span><strong>{money(table.current_total, session?.hotel.currency_code)}</strong></div><div className="card-actions table-card-actions"><button type="button" className="button button-secondary" onClick={() => { clearCall(); setSelected(table); setAssignWaiterId(table.active_session?.waiter_id ? String(table.active_session.waiter_id) : '') }}>View details</button><button type="button" className="button button-primary" onClick={() => { clearCall(); navigate(`/app/orders?table=${table.id}`) }}>Add order</button>{table.active_session.status === 'occupied' && can(session, 'billing.request') && <button type="button" className="button button-bill-request" disabled={requestingBillId === table.active_session.id} onClick={() => setBillRequestTable(table)}><ReceiptText size={14} />{requestingBillId === table.active_session.id ? 'Requesting…' : 'Request bill'}</button>}</div></> : <div className="empty-table">No active order{table.is_active && <button type="button" className="text-action" onClick={() => { clearCall(); navigate(`/app/orders?table=${table.id}`) }}>Open order</button>}</div>}
      {manageTables && !parcel && <button type="button" className="text-action" onClick={() => openTableForm(table)}><Pencil size={14} />Edit table</button>}
    </article>
  }
  return <section>
    <Modal open={tableFormOpen} title={editingTable ? `Edit ${editingTable.name}` : 'Add dining table'} onClose={closeTableForm}>
      <form className="compact-form" key={editingTable?.id ?? 'new'} onSubmit={saveTable}>
        {!editingTable && <label className="field"><span>Outlet</span><select name="outlet_id" required defaultValue={outlets.length === 1 ? outlets[0].id : ''}><option value="" disabled>Select outlet</option>{outlets.filter((outlet) => outlet.is_active).map((outlet) => <option value={outlet.id} key={outlet.id}>{outlet.name}</option>)}</select></label>}
        <label className="field"><span>Table name</span><input name="name" placeholder="e.g. Table 2" maxLength={80} defaultValue={editingTable?.name} required /></label>
        <label className="field"><span>Table code</span><input name="code" placeholder="e.g. T2" maxLength={30} defaultValue={editingTable?.code} required /></label>
        <label className="field"><span>Capacity</span><input name="capacity" type="number" min="1" max="999" defaultValue={editingTable?.capacity ?? 4} required /></label>
        {editingTable && <label className="check-row"><input type="checkbox" name="is_active" value="1" defaultChecked={editingTable.is_active} />Table is available for service</label>}
        <div className="form-action-row"><button type="button" className="button button-secondary" disabled={savingTable} onClick={closeTableForm}>Cancel</button><button className="button button-primary" disabled={savingTable}>{savingTable ? 'Saving…' : editingTable ? 'Save table' : 'Create table'}</button></div>
      </form>
    </Modal>
    <div className="page-heading"><div><p className="eyebrow">LIVE COUNTER BOARD</p><h1>Table status</h1><p>Live orders, kitchen progress and bill requests in one view.</p></div><div className="heading-actions">{can(session, 'sessions.open') && <button type="button" className="button button-secondary" disabled={startingParcel} onClick={() => void startParcel()}><Package size={16} />{startingParcel ? 'Opening…' : 'New parcel'}</button>}{manageTables && <button type="button" className="button button-primary" onClick={() => openTableForm()}><Plus size={16} />Add table</button>}<ConnectionState status={status} /></div></div>
    <div className="table-metrics"><Metric label="Total tables" count={metrics.total} tone="green" /><Metric label="Occupied" count={metrics.occupied} tone="blue" /><Metric label="Pending bill" count={metrics.pending} tone="amber" /><Metric label="Available" count={metrics.available} tone="red" /></div>
    <label className="catalog-search"><Search size={17} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search table or current item" /></label>
    <div className="floor-grid live-floor-grid">
      {can(session, 'sessions.open') && <button type="button" className="floor-card new-parcel-card" disabled={startingParcel} onClick={() => void startParcel()}><div className="floor-card-heading"><div><span className="table-state available">Parcel</span><h2>New parcel</h2><p>Cashier or waiter can take the order and send it to billing like a table.</p></div><Package size={27} /></div><span className="text-action">{startingParcel ? 'Opening…' : 'Start parcel order'}</span></button>}
      {dineInFiltered.map((table) => renderFloorCard(table))}
    </div>
    {parcelFiltered.length > 0 && <section className="parcel-board">
      <div className="data-card-heading"><Package size={19} /><h2>Parcels / takeaway</h2><span>{parcelFiltered.length} open</span></div>
      <div className="floor-grid live-floor-grid">
        {parcelFiltered.map((table) => renderFloorCard(table))}
      </div>
    </section>}
    <Modal open={Boolean(selected)} title={selected ? `${selected.name} · live order` : 'Table'} onClose={() => setSelected(null)}>{selected?.active_session && <div className="table-detail"><p><strong>{isParcelTable(selected) ? 'Parcel / takeaway' : `${selected.active_session.guest_count} guests`}</strong> · {selected.active_session.waiter?.name ?? 'No waiter assigned'}</p>{canAssignWaiter && <div className="assign-waiter-row"><label className="field"><span>Assigned waiter</span><select value={assignWaiterId} onChange={(event) => setAssignWaiterId(event.target.value)}>{staff.map((member) => <option value={member.id} key={member.id}>{member.name}</option>)}</select></label><button type="button" className="button button-secondary" disabled={savingTable || !assignWaiterId || Number(assignWaiterId) === selected.active_session.waiter_id} onClick={() => void assignWaiter()}>Assign</button></div>}{selected.active_session.orders.flatMap((order) => order.items).map((item) => <div className="detail-item" key={item.id}><span>{item.quantity} × {item.item_name}<small>{readable(item.status)}{item.kitchen_note ? ` · ${item.kitchen_note}` : ''}</small></span><strong>{money(item.line_total, session?.hotel.currency_code)}</strong>{canCancelItem(session, item) && selected.active_session?.status === 'occupied' && <button type="button" className="text-danger" title={item.status === 'pending' || item.fulfillment_mode === 'direct' ? 'Cancel this item' : 'Prepared cancellation requires extra permission and records wastage'} onClick={() => void cancelItem(item)}><X size={14} />Cancel</button>}</div>)}<div className="modal-total"><span>Current total</span><strong>{money(selected.current_total, session?.hotel.currency_code)}</strong></div><div className="form-action-row"><button type="button" className="button button-secondary" onClick={() => setSelected(null)}>Close</button><button type="button" className="button button-secondary" onClick={() => { setSelected(null); navigate(`/app/orders?table=${selected.id}`) }}>Add order</button>{selected.active_session.status === 'occupied' && can(session, 'billing.request') && <button type="button" className="button button-bill-request" onClick={() => { setSelected(null); setBillRequestTable(selected) }}><ReceiptText size={14} />Request bill</button>}{selected.display_status === 'pending_bill' && can(session, 'billing.view') && <button type="button" className="button button-primary" onClick={() => { setSelected(null); navigate('/app/billing') }}>Open bill</button>}</div></div>}</Modal>
    <ConfirmDialog open={Boolean(billRequestTable)} title="Request final bill?" text={`Send ${billRequestTable?.name ?? 'this table'} to the counter for billing? Additional orders will be disabled after confirmation.`} confirmLabel="Request bill" onClose={() => setBillRequestTable(null)} onConfirm={() => void requestBill()} />
    <PromptDialog open={Boolean(cancelTarget)} title={`Cancel ${cancelTarget?.item_name ?? 'item'}`} description="Cancelled items stay on the table history." label="Required reason" type="textarea" minLength={3} confirmLabel="Cancel item" onClose={() => setCancelTarget(null)} onConfirm={(reason) => void confirmTableCancel(reason)} />
    <Toast message={notice || error} tone={error ? 'error' : 'success'} onDismiss={() => { setNotice(''); setError('') }} />
  </section>
}

function Metric({ label, count, tone }: { label: string; count: number; tone: string }) { return <div className={`metric-card ${tone}`}><strong>{count}</strong><span>{label}</span></div> }
