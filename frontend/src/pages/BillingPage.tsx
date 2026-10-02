import { CircleDollarSign, History, Plus, ReceiptText, Undo2, X } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { InvoiceReceiptPanel } from '../components/InvoiceReceiptPanel'
import { Modal, Toast } from '../components/ui/Feedback'
import { useAuth } from '../features/auth/AuthContext'
import { can, canCancelItem } from '../features/auth/permissions'
import { idempotencyHeaders, useRestaurantRealtime } from '../features/realtime/useRestaurantRealtime'
import { api, errorMessage } from '../lib/api'
import type { ApiEnvelope, DiningSession, DiningTable, Invoice, OrderItem } from '../types/api'
import { groupBillLines, qtyLabel, statusSummary, takeExcludeUnits, type BillLineGroup } from '../lib/billLines'
import { openThermalReceiptWindow, printThermalReceipt, printsOnCustomerBill, writeThermalReceipt } from '../lib/thermalReceipt'
import { ConnectionState, isParcelTable, isPrimaryMergedTable, mergedTableLabel, money, readable, sessionCanTakeOrders, sessionDisplayName, unwrap, useOperationalRefresh, value } from './opsShared'
import { applyTableEvent } from '../features/realtime/applyRestaurantEvent'

export function BillingPage() {
  const { session, activeOutletId } = useAuth()
  const navigate = useNavigate()
  const [tables, setTables] = useState<DiningTable[]>([])
  const [owners, setOwners] = useState<Array<{ id: number; name: string }>>([])
  const [selectedSession, setSelectedSession] = useState<DiningSession | null>(null)
  const [invoice, setInvoice] = useState<Invoice | null>(null)
  const [discount, setDiscount] = useState('')
  const [discountPercent, setDiscountPercent] = useState('')
  const lastDiscountField = useRef<'amount' | 'percent'>('amount')
  const [chargedToUserId, setChargedToUserId] = useState('')
  const [closeWithoutSaleOpen, setCloseWithoutSaleOpen] = useState(false)
  const [closeWithoutSaleReason, setCloseWithoutSaleReason] = useState('')
  const [excludeTarget, setExcludeTarget] = useState<{ group: BillLineGroup<OrderItem>; quantity: string; reason: string } | null>(null)
  const [guest, setGuest] = useState({ customer_name: '', customer_phone: '', customer_email: '', customer_gstin: '' })
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const selectedBillingSessionId = selectedSession?.id
  const loadTables = useCallback(async () => {
    try {
      const result = unwrap(await api.get<ApiEnvelope<DiningTable[]>>('/api/v1/tables/status', { params: { outlet_id: activeOutletId } }))
      setTables(result)
    } catch (requestError) { setError(errorMessage(requestError)) }
  }, [activeOutletId])
  useEffect(() => {
    const task = window.setTimeout(() => {
      void api.get<ApiEnvelope<Array<{ id: number; name: string }>>>('/api/v1/billing-owners').then((response) => setOwners(unwrap(response))).catch(() => undefined)
    }, 0)
    return () => window.clearTimeout(task)
  }, [])
  const loadSelected = useCallback(async (sessionId: number) => {
    try { setSelectedSession(unwrap(await api.get<ApiEnvelope<DiningSession>>(`/api/v1/dining-sessions/${sessionId}`))) } catch (requestError) { setError(errorMessage(requestError)) }
  }, [])
  const loadInvoice = useCallback(async (invoiceId: number) => { try { setInvoice(unwrap(await api.get<ApiEnvelope<Invoice>>(`/api/v1/invoices/${invoiceId}`))) } catch (requestError) { setError(errorMessage(requestError)) } }, [])
  useEffect(() => { const task = window.setTimeout(() => void loadTables(), 0); return () => window.clearTimeout(task) }, [loadTables])
  useEffect(() => {
    if (selectedBillingSessionId) return
    const pending = tables.find((table) => table.display_status === 'pending_bill')?.active_session
    if (pending) setSelectedSession(pending)
  }, [tables, selectedBillingSessionId])
  useEffect(() => {
    if (!selectedBillingSessionId) return
    const task = window.setTimeout(() => void loadSelected(selectedBillingSessionId), 0)
    return () => window.clearTimeout(task)
  }, [selectedBillingSessionId, loadSelected])
  useEffect(() => { const task = window.setTimeout(() => { if (selectedSession?.invoice?.id) void loadInvoice(selectedSession.invoice.id); else setInvoice(null) }, 0); return () => window.clearTimeout(task) }, [selectedSession?.id, selectedSession?.invoice?.id, loadInvoice])
  const outletId = activeOutletId ?? tables[0]?.outlet_id
  const { status } = useRestaurantRealtime({ outletId, onUpdate: (event) => {
    if (event.type === 'connection_restored') { void loadTables(); if (selectedBillingSessionId) void loadSelected(selectedBillingSessionId); return }
    setTables((current) => {
      const result = applyTableEvent(current, event)
      if (!result.handled) { void loadTables(); return current }
      return result.tables
    })
    if (event.type === 'table_closed' && event.session_id === selectedBillingSessionId) {
      setSelectedSession(null)
      setInvoice(null)
      return
    }
    if (selectedBillingSessionId && event.session_id === selectedBillingSessionId && (event.type === 'order_sent' || event.type === 'item_cancelled' || event.type === 'bill_requested')) {
      void loadSelected(selectedBillingSessionId)
    }
    if (event.type === 'invoice_created' && event.session_id === selectedBillingSessionId && typeof event.data?.invoice_id === 'number') {
      void loadInvoice(event.data.invoice_id)
    }
    if (event.type === 'invoice_reopened' && selectedBillingSessionId && event.session_id === selectedBillingSessionId) {
      setInvoice(null)
      void loadSelected(selectedBillingSessionId)
    }
  } })
  useOperationalRefresh(loadTables, status)
  const billed = Boolean(invoice ?? selectedSession?.invoice)
  const billBase = selectedSession ? value(selectedSession.subtotal) + value(selectedSession.tax_amount) + value(selectedSession.service_charge_amount) : 0
  useEffect(() => {
    if (billed) return
    setGuest({ customer_name: '', customer_phone: '', customer_email: '', customer_gstin: '' })
  }, [billed, selectedSession?.id])
  useEffect(() => {
    if (!selectedSession || billed) return
    const amount = value(selectedSession.discount_amount)
    const storedPercent = selectedSession.discount_percent
    const base = value(selectedSession.subtotal) + value(selectedSession.tax_amount) + value(selectedSession.service_charge_amount)
    setDiscount(amount ? amount.toFixed(2) : '')
    if (storedPercent != null && storedPercent !== '') setDiscountPercent(String(Number(storedPercent)))
    else setDiscountPercent(base > 0 && amount > 0 ? ((amount / base) * 100).toFixed(2) : '')
  }, [selectedSession?.id, selectedSession?.discount_amount, selectedSession?.discount_percent, selectedSession?.subtotal, selectedSession?.tax_amount, selectedSession?.service_charge_amount, billed])
  const guestPayload = () => ({
    ...Object.fromEntries(Object.entries(guest).map(([key, value]) => [key, value.trim() || null])),
    charged_to_user_id: chargedToUserId ? Number(chargedToUserId) : null,
  })
  const applyDiscount = async () => {
    if (!selectedSession || !can(session, 'billing.discount')) return
    setBusy(true)
    try {
      const payload = lastDiscountField.current === 'percent'
        ? { discount_percent: value(discountPercent) }
        : { discount_amount: value(discount) }
      const updated = unwrap(await api.post<ApiEnvelope<DiningSession>>(`/api/v1/dining-sessions/${selectedSession.id}/discount`, payload))
      setSelectedSession(updated)
      setNotice('Discount applied to the session.')
    } catch (requestError) { setError(errorMessage(requestError)) } finally { setBusy(false) }
  }
  const createInvoice = async () => {
    if (!selectedSession || busy) return
    const popup = openThermalReceiptWindow()
    setBusy(true)
    try {
      const created = unwrap(await api.post<ApiEnvelope<Invoice>>(`/api/v1/dining-sessions/${selectedSession.id}/invoice`, guestPayload()))
      setInvoice(created)
      if (popup) {
        printReceipt(created, false, popup)
      } else {
        setNotice(`Invoice ${created.invoice_number} created.`)
        setError('Printing was blocked by the browser. Allow pop-ups and try again.')
      }
    } catch (requestError) {
      popup?.close()
      setError(errorMessage(requestError))
    } finally { setBusy(false) }
  }
  const requestBill = async () => { if (!selectedSession || busy) return; setBusy(true); try { const updated = unwrap(await api.post<ApiEnvelope<DiningSession>>(`/api/v1/dining-sessions/${selectedSession.id}/request-bill`)); setSelectedSession(updated); setNotice('Ready to apply discount and create the bill.') } catch (requestError) { setError(errorMessage(requestError)) } finally { setBusy(false) } }
  const cancelLine = async () => {
    if (!excludeTarget || excludeTarget.reason.trim().length < 3) return
    const qty = value(excludeTarget.quantity)
    const cancellable = excludeTarget.group.items.filter((item) => canCancelItem(session, item))
    const units = takeExcludeUnits(cancellable, qty)
    if (qty <= 0 || units.reduce((sum, unit) => sum + unit.quantity, 0) < qty) {
      setError('You can only exclude a quantity you are allowed to cancel.')
      return
    }
    setBusy(true)
    try {
      for (const unit of units) {
        const whole = Math.abs(unit.quantity - Number(unit.item.quantity)) < 0.0005
        await api.post(`/api/v1/order-items/${unit.item.id}/cancel`, whole ? { reason: excludeTarget.reason.trim() } : { reason: excludeTarget.reason.trim(), quantity: unit.quantity })
      }
      setExcludeTarget(null)
      if (selectedSession) await loadSelected(selectedSession.id)
      await loadTables()
      setNotice(`${qtyLabel(qty)} × ${excludeTarget.group.item_name} removed from this bill.`)
    } catch (requestError) { setError(errorMessage(requestError)) } finally { setBusy(false) }
  }
  const addUnit = async (group: BillLineGroup<OrderItem>) => {
    if (!selectedSession || !group.product_id || busy || !can(session, 'orders.create')) return
    setBusy(true)
    try {
      const updated = unwrap(await api.post<ApiEnvelope<DiningSession>>(`/api/v1/dining-sessions/${selectedSession.id}/orders`, { items: [{ product_id: group.product_id, quantity: 1 }] }, { headers: idempotencyHeaders() }))
      setSelectedSession(updated)
      await loadTables()
      setNotice(`Added 1 × ${group.item_name}.`)
    } catch (requestError) { setError(errorMessage(requestError)) } finally { setBusy(false) }
  }
  const closeWithoutSale = async () => { if (!selectedSession || busy || closeWithoutSaleReason.trim().length < 3) return; setBusy(true); setError(''); try { await api.post(`/api/v1/dining-sessions/${selectedSession.id}/close-without-sale`, { reason: closeWithoutSaleReason.trim() }); setCloseWithoutSaleOpen(false); setCloseWithoutSaleReason(''); setSelectedSession(null); setInvoice(null); await loadTables(); setNotice('Zero-value session closed without a sale. The reason was added to the audit log.') } catch (requestError) { setError(errorMessage(requestError)) } finally { setBusy(false) } }
  const printReceipt = (bill: Invoice, duplicate: boolean, popup?: Window) => {
    if (!session) { popup?.close(); return }
    const items = (bill.items?.length ? bill.items : selectedSession?.orders.flatMap((order) => order.items).filter((item) => item.status !== 'cancelled')) ?? []
    const input = {
      hotel: session.hotel,
      outlet: session.outlets.find((outlet) => outlet.id === activeOutletId) ?? session.outlets[0],
      cashier: session.user.name,
      tableName: sessionDisplayName(selectedSession, tables.find((table) => table.active_session?.id === selectedSession?.id && isPrimaryMergedTable(table))?.name ?? selectedSession?.dining_table?.name ?? bill.dining_session?.dining_table?.name ?? 'Table'),
      serviceLabel: isParcelTable(tables.find((table) => table.active_session?.id === selectedSession?.id) ?? (selectedSession?.dining_table as DiningTable | undefined)) ? 'Parcel' : 'Dine In',
      invoice: bill,
      items,
      duplicate,
    }
    const printed = popup ? (writeThermalReceipt(popup, input), true) : printThermalReceipt(input)
    if (!printed) setError('Printing was blocked by the browser. Allow pop-ups and try again.')
    else { setError(''); setNotice(duplicate ? 'Duplicate receipt sent to the printer.' : 'Bill sent to the printer.') }
  }
  const openForItems = Boolean(selectedSession) && sessionCanTakeOrders(selectedSession) && !billed
  const billItems = !selectedSession || billed ? [] : (selectedSession.orders ?? []).flatMap((order) => order.items).filter((item) => item.status !== 'cancelled')
  const customerItems = groupBillLines(billItems.filter(printsOnCustomerBill))
  const internalItems = groupBillLines(billItems.filter((item) => !printsOnCustomerBill(item)))
  const sectionTotal = (items: typeof customerItems) => items.reduce((sum, item) => sum + item.line_total, 0)
  const changeDiscountPercent = (raw: string) => {
    lastDiscountField.current = 'percent'
    setDiscountPercent(raw)
    const pct = Number(raw)
    if (raw === '' || Number.isNaN(pct) || billBase <= 0) {
      if (raw === '') setDiscount('')
      return
    }
    setDiscount(((billBase * pct) / 100).toFixed(2))
  }
  const changeDiscountAmount = (raw: string) => {
    lastDiscountField.current = 'amount'
    setDiscount(raw)
    const amount = Number(raw)
    if (raw === '' || Number.isNaN(amount) || billBase <= 0) {
      if (raw === '') setDiscountPercent('')
      return
    }
    setDiscountPercent(((amount / billBase) * 100).toFixed(2))
  }
  const renderBillLine = (group: BillLineGroup<OrderItem>) => {
    const canExclude = openForItems && group.items.some((item) => canCancelItem(session, item))
    const canAdd = openForItems && Boolean(group.product_id) && can(session, 'orders.create')
    return <div className="invoice-line bill-qty-line" key={group.key}>
      <span>{group.item_name}{group.statuses.length ? <small>{statusSummary(group)}</small> : null}</span>
      <b>{qtyLabel(group.quantity)}</b>
      <span>{money(group.unit_price, session?.hotel.currency_code)}</span>
      <strong>{money(group.line_total, session?.hotel.currency_code)}</strong>
      {(canExclude || canAdd) && <span className="bill-line-actions">
        {canExclude && <button type="button" className="text-danger" title={`Exclude ${group.item_name}`} onClick={() => setExcludeTarget({ group, quantity: '1', reason: '' })}><X size={15} /></button>}
        {canAdd && <button type="button" className="text-add" title={`Add ${group.item_name}`} onClick={() => void addUnit(group)}><Plus size={15} /></button>}
      </span>}
    </div>
  }
  const selectedTable = tables.find((table) => table.active_session?.id === selectedSession?.id)
  return <section className="billing-page">
    <div className="page-heading"><div><p className="eyebrow">COUNTER BILLING</p><h1>Settle table bills</h1><p>Create the final bill, take split payments, and retain corrections safely.</p></div><div className="heading-actions"><Link className="button button-secondary" to="/app/billing/history"><History size={15} />Previous bills</Link><ConnectionState status={status} /></div></div>
    <div className="billing-layout enhanced-billing-layout"><aside className="billing-session-list"><h2>Open tables & parcels</h2>{tables.filter((table) => table.active_session && isPrimaryMergedTable(table)).map((table) => { const label = mergedTableLabel(table); return <button type="button" className={`bill-table ${selectedSession?.id === table.active_session?.id ? 'active' : ''} ${table.display_status === 'pending_bill' ? 'needs-bill' : ''}`} key={table.active_session?.id ?? table.id} onClick={() => setSelectedSession(table.active_session ?? null)}><span><strong>{label.title}</strong><small>{isParcelTable(table) ? 'Parcel' : readable(table.display_status)} · {isParcelTable(table) ? 'takeaway' : `${table.active_session?.guest_count} guests`}{label.detail ? ` · ${label.detail}` : ''}</small></span><b>{money(table.current_total, session?.hotel.currency_code)}</b></button> })}</aside>
      <main className="invoice-panel receipt-panel">{!selectedSession ? <div className="invoice-empty"><CircleDollarSign size={38} /><h2>Select a table</h2><p>Pending-bill tables are highlighted so the counter can settle them quickly.</p></div> : invoice ? <InvoiceReceiptPanel invoice={invoice} busy={busy} tableName={sessionDisplayName(selectedSession, selectedTable && isPrimaryMergedTable(selectedTable) ? selectedTable.name : selectedSession.dining_table?.name)} serviceLabel={isParcelTable(selectedTable ?? (selectedSession.dining_table as DiningTable | undefined)) ? 'Parcel' : 'Dine In'} onBusy={setBusy} onInvoiceChange={(updated) => { setInvoice(updated); if (updated.payment_status === 'paid' && selectedSession) setTables((openTables) => openTables.map((entry) => entry.active_session?.id === selectedSession.id ? { ...entry, display_status: 'available', current_total: '0.00', active_session: null } : entry)) }} onNotice={setNotice} onError={setError} onReopened={(updated) => { setInvoice(null); setSelectedSession(updated); void loadTables() }} onVoided={() => { setInvoice(null); setSelectedSession(null); void loadTables() }} /> : selectedSession.invoice ? <div className="invoice-empty"><h2>Loading bill…</h2></div> : <>
        <div className="invoice-heading"><div><h2>{selectedSession.display_name ?? selectedSession.dining_table?.name ?? 'Current table'}</h2><span>Bill not yet created</span></div></div>
        <div className="invoice-lines">
          <div className="bill-line-section">
            {internalItems.length > 0 && customerItems.length > 0 && <h3>Customer bill</h3>}
            {customerItems.length > 0 && <div className="bill-qty-head"><span>Item</span><span>Qty</span><span>Price</span><span>Amt</span><span></span></div>}
            {customerItems.map(renderBillLine)}
            {customerItems.length > 0 && <div className="bill-line-subtotal"><span>Items total</span><strong>{money(sectionTotal(customerItems), session?.hotel.currency_code)}</strong></div>}
            {!customerItems.length && !internalItems.length && <p className="bill-empty-lines">No items on this bill yet.</p>}
          </div>
          {internalItems.length > 0 && <div className="bill-line-section internal-bill-section">
            <h3>Not on customer bill</h3>
            <div className="bill-qty-head"><span>Item</span><span>Qty</span><span>Price</span><span>Amt</span><span></span></div>
            {internalItems.map(renderBillLine)}
            <div className="bill-line-subtotal"><span>Internal total</span><strong>{money(sectionTotal(internalItems), session?.hotel.currency_code)}</strong></div>
          </div>}
        </div>
        {can(session, 'orders.create') && openForItems && <button type="button" className="button button-secondary" onClick={() => navigate(`/app/orders?table=${selectedSession.dining_table_id}`)}><Plus size={15} />Include items</button>}
        {can(session, 'billing.discount') && <div className="discount-row discount-linked-row"><label className="field"><span>Discount %</span><input type="number" min="0" max="100" step="0.01" value={discountPercent} onChange={(event) => changeDiscountPercent(event.target.value)} placeholder="0" /></label><label className="field"><span>Discount amount</span><input type="number" min="0" step="0.01" value={discount} onChange={(event) => changeDiscountAmount(event.target.value)} placeholder="0.00" /></label><button type="button" className="button button-secondary" disabled={busy} onClick={() => void applyDiscount()}>Apply</button></div>}
        {can(session, 'billing.create') && <div className="discount-row guest-details">{owners.length > 0 && <label className="field"><span>Put on owner</span><select value={chargedToUserId} onChange={(event) => { const next = event.target.value; setChargedToUserId(next); const owner = owners.find((entry) => String(entry.id) === next); if (owner) setGuest((currentGuest) => ({ ...currentGuest, customer_name: owner.name })) }}><option value="">Regular guest bill</option>{owners.map((owner) => <option key={owner.id} value={owner.id}>{owner.name}</option>)}</select></label>}<label className="field"><span>Guest / owner name</span><input value={guest.customer_name} onChange={(event) => setGuest((currentGuest) => ({ ...currentGuest, customer_name: event.target.value }))} placeholder="Not required to bill" /></label><label className="field"><span>Phone (optional)</span><input value={guest.customer_phone} onChange={(event) => setGuest((currentGuest) => ({ ...currentGuest, customer_phone: event.target.value }))} /></label><label className="field"><span>Email (optional)</span><input type="email" value={guest.customer_email} onChange={(event) => setGuest((currentGuest) => ({ ...currentGuest, customer_email: event.target.value }))} /></label><label className="field"><span>GSTIN (optional)</span><input value={guest.customer_gstin} onChange={(event) => setGuest((currentGuest) => ({ ...currentGuest, customer_gstin: event.target.value }))} /></label></div>}
        <div className="invoice-total"><span>Total</span><strong>{money(selectedSession.total_amount, session?.hotel.currency_code)}</strong>{value(selectedSession.discount_amount) > 0 ? <span>Discount {money(selectedSession.discount_amount, session?.hotel.currency_code)}</span> : null}</div>
        {selectedSession.status === 'occupied' && can(session, 'billing.request') && <button type="button" className="button button-secondary button-full" disabled={busy} onClick={() => void requestBill()}><ReceiptText size={16} />Request bill</button>}
        {value(selectedSession.total_amount) === 0 ? can(session, 'billing.close_without_sale') ? <button type="button" className="button button-secondary button-full close-without-sale" disabled={busy || selectedSession.status !== 'pending_bill'} onClick={() => setCloseWithoutSaleOpen(true)}><Undo2 size={16} />{selectedSession.status === 'pending_bill' ? 'Close without sale' : 'Request bill first'}</button> : <p className="zero-sale-message">This zero-value session requires an authorized user to close it without a sale.</p> : <button type="button" className="button button-primary button-full" disabled={busy || selectedSession.status !== 'pending_bill'} onClick={() => void createInvoice()}><ReceiptText size={16} />{busy ? 'Creating…' : 'Create final bill'}</button>}
      </>}</main></div>
    <Modal open={closeWithoutSaleOpen} title="Close without sale" onClose={() => !busy && setCloseWithoutSaleOpen(false)}><p className="modal-intro">No invoice will be created. The table will become available and this reason will remain in the audit log.</p><label className="field"><span>Required reason</span><textarea rows={4} maxLength={1000} value={closeWithoutSaleReason} onChange={(event) => setCloseWithoutSaleReason(event.target.value)} placeholder="For example: all items cancelled before preparation" /></label><div className="form-action-row"><button type="button" className="button button-secondary" disabled={busy} onClick={() => setCloseWithoutSaleOpen(false)}>Cancel</button><button type="button" className="button button-primary" disabled={busy || closeWithoutSaleReason.trim().length < 3} onClick={() => void closeWithoutSale()}>{busy ? 'Closing…' : 'Close table'}</button></div></Modal>
    <Modal open={Boolean(excludeTarget)} title={`Exclude ${excludeTarget?.group.item_name ?? 'item'}?`} description="Cancelled quantity stays in history. The rest remains on the bill." onClose={() => !busy && setExcludeTarget(null)}>{excludeTarget && <><p className="modal-intro">{excludeTarget.group.quantity > 1 ? `${qtyLabel(excludeTarget.group.quantity)} on this bill. Choose how many to exclude.` : 'This is the only remaining unit of this item.'}</p>{excludeTarget.group.quantity > 1 && <label className="field"><span>Quantity to exclude</span><input type="number" min={1} max={excludeTarget.group.quantity} step="1" value={excludeTarget.quantity} onChange={(event) => setExcludeTarget({ ...excludeTarget, quantity: event.target.value })} /></label>}<label className="field"><span>Required reason</span><textarea rows={3} maxLength={1000} value={excludeTarget.reason} onChange={(event) => setExcludeTarget({ ...excludeTarget, reason: event.target.value })} /></label><div className="form-action-row"><button type="button" className="button button-secondary" disabled={busy} onClick={() => setExcludeTarget(null)}>Cancel</button><button type="button" className="button button-primary" disabled={busy || excludeTarget.reason.trim().length < 3 || value(excludeTarget.quantity) < 1 || value(excludeTarget.quantity) > excludeTarget.group.quantity} onClick={() => void cancelLine()}>{busy ? 'Saving…' : 'Exclude'}</button></div></>}</Modal>
    <Toast message={error || notice} tone={error ? 'error' : 'success'} onDismiss={() => { setNotice(''); setError('') }} />
  </section>
}
