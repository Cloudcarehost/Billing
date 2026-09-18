import { CircleDollarSign, CreditCard, Plus, Printer, ReceiptText, Undo2, X } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { Modal, PromptDialog, Toast } from '../components/ui/Feedback'
import { useAuth } from '../features/auth/AuthContext'
import { can } from '../features/auth/permissions'
import { idempotencyHeaders, useRestaurantRealtime } from '../features/realtime/useRestaurantRealtime'
import { api, errorMessage } from '../lib/api'
import type { ApiEnvelope, DiningSession, DiningTable, Invoice } from '../types/api'
import { printThermalReceipt } from '../lib/thermalReceipt'
import { ConnectionState, money, readable, unwrap, useOperationalRefresh, value } from './opsShared'
import { applyTableEvent } from '../features/realtime/applyRestaurantEvent'

export function BillingPage() {
  const { session, activeOutletId } = useAuth()
  const [tables, setTables] = useState<DiningTable[]>([])
  const [selectedSession, setSelectedSession] = useState<DiningSession | null>(null)
  const [invoice, setInvoice] = useState<Invoice | null>(null)
  const [discount, setDiscount] = useState('')
  const [paymentOpen, setPaymentOpen] = useState(false)
  const [payments, setPayments] = useState<Array<{ method: 'cash' | 'card' | 'upi'; amount: string; reference_number: string }>>([{ method: 'cash', amount: '', reference_number: '' }])
  const [voidOpen, setVoidOpen] = useState(false)
  const [closeWithoutSaleOpen, setCloseWithoutSaleOpen] = useState(false)
  const [closeWithoutSaleReason, setCloseWithoutSaleReason] = useState('')
  const [refundPayment, setRefundPayment] = useState<{ id: number; amount: string; refunded?: string; reason: string } | null>(null)
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
  } })
  useOperationalRefresh(loadTables, status)
  const current = invoice ?? selectedSession?.invoice ?? null
  useEffect(() => {
    setGuest({
      customer_name: current?.customer_name ?? '',
      customer_phone: current?.customer_phone ?? '',
      customer_email: current?.customer_email ?? '',
      customer_gstin: current?.customer_gstin ?? '',
    })
  }, [current?.id, current?.customer_name, current?.customer_phone, current?.customer_email, current?.customer_gstin])
  const guestPayload = () => Object.fromEntries(Object.entries(guest).map(([key, value]) => [key, value.trim() || null]))
  const applyDiscount = async () => { if (!selectedSession || !can(session, 'billing.discount')) return; setBusy(true); try { const updated = unwrap(await api.post<ApiEnvelope<DiningSession>>(`/api/v1/dining-sessions/${selectedSession.id}/discount`, { discount_amount: value(discount) })); setSelectedSession(updated); setNotice('Discount applied to the session.') } catch (requestError) { setError(errorMessage(requestError)) } finally { setBusy(false) } }
  const createInvoice = async () => { if (!selectedSession || busy) return; setBusy(true); try { const created = unwrap(await api.post<ApiEnvelope<Invoice>>(`/api/v1/dining-sessions/${selectedSession.id}/invoice`, guestPayload())); setInvoice(created); setNotice(`Invoice ${created.invoice_number} created.`); printReceipt(created, false) } catch (requestError) { setError(errorMessage(requestError)) } finally { setBusy(false) } }
  const saveGuest = async () => { if (!current || busy) return; setBusy(true); try { const updated = unwrap(await api.patch<ApiEnvelope<Invoice>>(`/api/v1/invoices/${current.id}/guest`, guestPayload())); setInvoice(updated); setNotice('Customer details saved. Billing was not blocked.') } catch (requestError) { setError(errorMessage(requestError)) } finally { setBusy(false) } }
  const closeWithoutSale = async () => { if (!selectedSession || busy || closeWithoutSaleReason.trim().length < 3) return; setBusy(true); setError(''); try { await api.post(`/api/v1/dining-sessions/${selectedSession.id}/close-without-sale`, { reason: closeWithoutSaleReason.trim() }); setCloseWithoutSaleOpen(false); setCloseWithoutSaleReason(''); setSelectedSession(null); setInvoice(null); await loadTables(); setNotice('Zero-value session closed without a sale. The reason was added to the audit log.') } catch (requestError) { setError(errorMessage(requestError)) } finally { setBusy(false) } }
  const pay = async () => { if (!current || busy) return; const valid = payments.filter((payment) => value(payment.amount) > 0); if (!valid.length) return setError('Enter at least one payment amount.'); if (valid.reduce((sum, payment) => sum + value(payment.amount), 0) > value(current.balance_amount)) return setError('Split payments cannot exceed the balance.'); setBusy(true); try { let updated = current; for (const payment of valid) updated = unwrap(await api.post<ApiEnvelope<Invoice>>(`/api/v1/invoices/${current.id}/payments`, payment, { headers: idempotencyHeaders() })); setInvoice(updated); setPaymentOpen(false); if (updated.payment_status === 'paid' && selectedSession) setTables((openTables) => openTables.map((entry) => entry.active_session?.id === selectedSession.id ? { ...entry, display_status: 'available', current_total: '0.00', active_session: null } : entry)); setNotice(updated.payment_status === 'paid' ? 'Payment complete. Table is now available.' : 'Partial payment recorded.') } catch (requestError) { setError(errorMessage(requestError)) } finally { setBusy(false) } }
  const voidInvoice = async (reason: string) => {
    if (!current) return
    setBusy(true)
    try { await api.post<ApiEnvelope<Invoice>>(`/api/v1/invoices/${current.id}/void`, { reason }); setInvoice(null); setSelectedSession(null); setVoidOpen(false); await loadTables(); setNotice('Bill voided and the table is available again. The reason remains in the audit trail.') } catch (requestError) { setError(errorMessage(requestError)) } finally { setBusy(false) }
  }
  const refund = async () => {
    if (!current || !refundPayment || refundPayment.reason.trim().length < 3) return
    setBusy(true)
    try { setInvoice(unwrap(await api.post<ApiEnvelope<Invoice>>(`/api/v1/invoices/${current.id}/payments/${refundPayment.id}/refund`, { amount: value(refundPayment.amount), reason: refundPayment.reason.trim() }))); setRefundPayment(null); setNotice('Refund recorded.') } catch (requestError) { setError(errorMessage(requestError)) } finally { setBusy(false) }
  }
  const printReceipt = (bill: Invoice, duplicate: boolean) => {
    if (!session) return
    const items = (bill.items?.length ? bill.items : selectedSession?.orders.flatMap((order) => order.items).filter((item) => item.status !== 'cancelled')) ?? []
    const printed = printThermalReceipt({
      hotel: session.hotel,
      outlet: session.outlets.find((outlet) => outlet.id === activeOutletId) ?? session.outlets[0],
      cashier: session.user.name,
      tableName: tables.find((table) => table.active_session?.id === selectedSession?.id)?.name ?? selectedSession?.dining_table?.name ?? bill.dining_session?.dining_table?.name ?? 'Table',
      invoice: bill,
      items,
      duplicate,
    })
    if (!printed) setError('The print dialog could not be opened. Try Print again.')
    else setNotice(duplicate ? 'Duplicate receipt sent to the printer.' : 'Bill sent to the printer.')
  }
  const reprint = async () => {
    if (!current) return
    printReceipt({ ...current, reprint_count: (current.reprint_count ?? 0) + 1 }, true)
    try {
      const reprinted = unwrap(await api.post<ApiEnvelope<Invoice>>(`/api/v1/invoices/${current.id}/reprint`))
      setInvoice((existing) => existing ? { ...existing, reprint_count: reprinted.reprint_count } : reprinted)
    } catch (requestError) { setError(errorMessage(requestError)) }
  }
  return <section className="billing-page">
    <div className="page-heading"><div><p className="eyebrow">COUNTER BILLING</p><h1>Settle table bills</h1><p>Create the final bill, take split payments, and retain corrections safely.</p></div><ConnectionState status={status} /></div>
    <div className="billing-layout enhanced-billing-layout"><aside className="billing-session-list"><h2>Open tables</h2>{tables.filter((table) => table.active_session).map((table) => <button type="button" className={`bill-table ${selectedSession?.id === table.active_session?.id ? 'active' : ''} ${table.display_status === 'pending_bill' ? 'needs-bill' : ''}`} key={table.id} onClick={() => setSelectedSession(table.active_session ?? null)}><span><strong>{table.name}</strong><small>{readable(table.display_status)} · {table.active_session?.guest_count} guests</small></span><b>{money(table.current_total, session?.hotel.currency_code)}</b></button>)}</aside>
      <main className="invoice-panel receipt-panel">{!selectedSession ? <div className="invoice-empty"><CircleDollarSign size={38} /><h2>Select a table</h2><p>Pending-bill tables are highlighted so the counter can settle them quickly.</p></div> : <>
        <div className="invoice-heading"><div><h2>{current?.invoice_number ?? selectedSession.dining_table?.name ?? 'Current table'}</h2><span>{current ? `${readable(current.status)} · ${readable(current.payment_status)}` : 'Bill not yet created'}</span></div>{current && <div className="receipt-actions"><button type="button" className="button button-secondary" onClick={() => void reprint()}><Printer size={15} />Print</button>{can(session, 'billing.void') && current.status === 'issued' && <button type="button" className="button button-secondary" onClick={() => setVoidOpen(true)}><Undo2 size={15} />Void</button>}</div>}</div>
        <div className="invoice-lines">{selectedSession.orders.flatMap((order) => order.items).filter((item) => item.status !== 'cancelled').map((item) => <div key={item.id}><span>{item.quantity} × {item.item_name}<small>{readable(item.status)}</small></span><strong>{money(item.line_total, session?.hotel.currency_code)}</strong></div>)}</div>
        {!current && can(session, 'billing.discount') && <div className="discount-row"><label className="field"><span>Authorized discount</span><input type="number" min="0" value={discount} onChange={(event) => setDiscount(event.target.value)} placeholder="0.00" /></label><button type="button" className="button button-secondary" disabled={busy} onClick={() => void applyDiscount()}>Apply</button></div>}
        {can(session, 'billing.create') && selectedSession.status === 'pending_bill' && <div className="discount-row guest-details"><label className="field"><span>Guest name (optional)</span><input value={guest.customer_name} onChange={(event) => setGuest((currentGuest) => ({ ...currentGuest, customer_name: event.target.value }))} placeholder="Not required to bill" /></label><label className="field"><span>Phone (optional)</span><input value={guest.customer_phone} onChange={(event) => setGuest((currentGuest) => ({ ...currentGuest, customer_phone: event.target.value }))} /></label><label className="field"><span>Email (optional)</span><input type="email" value={guest.customer_email} onChange={(event) => setGuest((currentGuest) => ({ ...currentGuest, customer_email: event.target.value }))} /></label><label className="field"><span>GSTIN (optional)</span><input value={guest.customer_gstin} onChange={(event) => setGuest((currentGuest) => ({ ...currentGuest, customer_gstin: event.target.value }))} /></label>{current && current.status !== 'voided' && <button type="button" className="button button-secondary" disabled={busy} onClick={() => void saveGuest()}>Save guest details</button>}</div>}
        <div className="invoice-total"><span>Total</span><strong>{money(current?.total_amount ?? selectedSession.total_amount, session?.hotel.currency_code)}</strong>{current && <span>Paid {money(current.paid_amount, session?.hotel.currency_code)} · Balance {money(current.balance_amount, session?.hotel.currency_code)}</span>}</div>
        {!current ? value(selectedSession.total_amount) === 0 ? can(session, 'billing.close_without_sale') ? <button type="button" className="button button-secondary button-full close-without-sale" disabled={busy || selectedSession.status !== 'pending_bill'} onClick={() => setCloseWithoutSaleOpen(true)}><Undo2 size={16} />{selectedSession.status === 'pending_bill' ? 'Close without sale' : 'Request bill first'}</button> : <p className="zero-sale-message">This zero-value session requires an authorized user to close it without a sale.</p> : <button type="button" className="button button-primary button-full" disabled={busy || selectedSession.status !== 'pending_bill'} onClick={() => void createInvoice()}><ReceiptText size={16} />{busy ? 'Creating…' : 'Create final bill'}</button> : <><div className="payment-summary">{current.payments?.map((payment) => <div key={payment.id}><span>{readable(payment.method)}<small>{new Date(payment.paid_at).toLocaleString()}</small></span><strong>{money(payment.amount, session?.hotel.currency_code)}</strong>{can(session, 'billing.refund') && payment.type !== 'refund' && Number(payment.amount) - Number(payment.refunded_amount ?? 0) > 0 && <button type="button" className="text-danger" onClick={() => setRefundPayment({ id: payment.id, amount: (Number(payment.amount) - Number(payment.refunded_amount ?? 0)).toFixed(2), refunded: payment.refunded_amount ?? '0', reason: '' })}>Refund remaining {(Number(payment.amount) - Number(payment.refunded_amount ?? 0)).toFixed(2)}</button>}</div>)}</div>{current.status === 'issued' && value(current.balance_amount) > 0 && <button type="button" className="button button-primary button-full" onClick={() => { setPayments([{ method: 'cash', amount: current.balance_amount, reference_number: '' }]); setPaymentOpen(true) }}><CreditCard size={16} />Take payment</button>}</>}
      </>}</main></div>
    <Modal open={paymentOpen} title="Split payment" onClose={() => !busy && setPaymentOpen(false)}><p className="modal-intro">Balance: <strong>{money(current?.balance_amount, session?.hotel.currency_code)}</strong></p><div className="split-payment-list">{payments.map((payment, index) => <div className="split-payment" key={index}><label className="field"><span>Method</span><select value={payment.method} onChange={(event) => setPayments((currentPayments) => currentPayments.map((entry, entryIndex) => entryIndex === index ? { ...entry, method: event.target.value as 'cash' | 'card' | 'upi' } : entry))}><option value="cash">Cash</option><option value="card">Card</option><option value="upi">UPI</option></select></label><label className="field"><span>Amount</span><input type="number" min="0" value={payment.amount} onChange={(event) => setPayments((currentPayments) => currentPayments.map((entry, entryIndex) => entryIndex === index ? { ...entry, amount: event.target.value } : entry))} /></label><label className="field"><span>Reference (optional)</span><input value={payment.reference_number} onChange={(event) => setPayments((currentPayments) => currentPayments.map((entry, entryIndex) => entryIndex === index ? { ...entry, reference_number: event.target.value } : entry))} /></label>{payments.length > 1 && <button type="button" className="remove-payment" onClick={() => setPayments((currentPayments) => currentPayments.filter((_, entryIndex) => entryIndex !== index))}><X size={16} /></button>}</div>)}</div><div className="form-action-row"><button type="button" className="button button-secondary" onClick={() => setPayments((currentPayments) => [...currentPayments, { method: 'cash', amount: '', reference_number: '' }])}><Plus size={15} />Split payment</button><button type="button" className="button button-primary" disabled={busy} onClick={() => void pay()}>{busy ? 'Saving…' : 'Confirm payment'}</button></div></Modal>
    <Modal open={closeWithoutSaleOpen} title="Close without sale" onClose={() => !busy && setCloseWithoutSaleOpen(false)}><p className="modal-intro">No invoice will be created. The table will become available and this reason will remain in the audit log.</p><label className="field"><span>Required reason</span><textarea rows={4} maxLength={1000} value={closeWithoutSaleReason} onChange={(event) => setCloseWithoutSaleReason(event.target.value)} placeholder="For example: all items cancelled before preparation" /></label><div className="form-action-row"><button type="button" className="button button-secondary" disabled={busy} onClick={() => setCloseWithoutSaleOpen(false)}>Cancel</button><button type="button" className="button button-primary" disabled={busy || closeWithoutSaleReason.trim().length < 3} onClick={() => void closeWithoutSale()}>{busy ? 'Closing…' : 'Close table'}</button></div></Modal>
    <PromptDialog open={voidOpen} title="Void invoice" description="This never deletes the bill. It creates a permanent void record that stays in the audit trail." label="Required reason" type="textarea" minLength={3} confirmLabel="Void bill" busy={busy} onClose={() => !busy && setVoidOpen(false)} onConfirm={(reason) => void voidInvoice(reason)} />
    <Modal open={Boolean(refundPayment)} title="Refund payment" description="Refunds cannot exceed the remaining amount on this payment." onClose={() => !busy && setRefundPayment(null)}>{refundPayment && <><p className="modal-intro">You can refund up to the remaining amount{refundPayment.refunded && Number(refundPayment.refunded) > 0 ? ` (${refundPayment.refunded} already refunded)` : ''}.</p><label className="field"><span>Refund amount</span><input type="number" min="0.01" step="0.01" max={refundPayment.amount} value={refundPayment.amount} onChange={(event) => setRefundPayment({ ...refundPayment, amount: event.target.value })} /></label><label className="field"><span>Required reason</span><textarea rows={3} value={refundPayment.reason} onChange={(event) => setRefundPayment({ ...refundPayment, reason: event.target.value })} /></label><div className="form-action-row"><button type="button" className="button button-secondary button-touch" onClick={() => setRefundPayment(null)}>Cancel</button><button type="button" className="button button-primary button-touch" disabled={busy || refundPayment.reason.trim().length < 3} onClick={() => void refund()}>{busy ? 'Saving…' : 'Confirm refund'}</button></div></>}</Modal>
    <Toast message={notice || error} tone={error ? 'error' : 'success'} onDismiss={() => { setNotice(''); setError('') }} />
  </section>
}
