import { CreditCard, Plus, Printer, Undo2, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Modal, PromptDialog } from './ui/Feedback'
import { useAuth } from '../features/auth/AuthContext'
import { can } from '../features/auth/permissions'
import { idempotencyHeaders } from '../features/realtime/useRestaurantRealtime'
import { api, errorMessage } from '../lib/api'
import { printThermalReceipt, printsOnCustomerBill } from '../lib/thermalReceipt'
import type { ApiEnvelope, DiningSession, Invoice } from '../types/api'
import { money, readable, unwrap, value } from '../pages/opsShared'

type Guest = { customer_name: string; customer_phone: string; customer_email: string; customer_gstin: string }

export function InvoiceReceiptPanel({
  invoice,
  busy,
  tableName,
  serviceLabel = 'Dine In',
  onBusy,
  onInvoiceChange,
  onNotice,
  onError,
  onReopened,
  onVoided,
}: {
  invoice: Invoice
  busy: boolean
  tableName: string
  serviceLabel?: string
  onBusy: (busy: boolean) => void
  onInvoiceChange: (invoice: Invoice) => void
  onNotice: (message: string) => void
  onError: (message: string) => void
  onReopened?: (session: DiningSession) => void
  onVoided?: () => void
}) {
  const { session, activeOutletId } = useAuth()
  const [guest, setGuest] = useState<Guest>({ customer_name: '', customer_phone: '', customer_email: '', customer_gstin: '' })
  const [paymentOpen, setPaymentOpen] = useState(false)
  const [payments, setPayments] = useState<Array<{ method: 'cash' | 'card' | 'upi'; amount: string; reference_number: string }>>([{ method: 'cash', amount: '', reference_number: '' }])
  const [voidOpen, setVoidOpen] = useState(false)
  const [reopenOpen, setReopenOpen] = useState(false)
  const [refundPayment, setRefundPayment] = useState<{ id: number; amount: string; refunded?: string; reason: string } | null>(null)
  const currency = session?.hotel.currency_code
  const items = invoice.items ?? []
  const customerItems = items.filter(printsOnCustomerBill)
  const internalItems = items.filter((item) => !printsOnCustomerBill(item))
  const sectionTotal = (rows: typeof items) => rows.reduce((sum, item) => sum + value(item.line_total), 0)

  useEffect(() => {
    setGuest({
      customer_name: invoice.customer_name ?? '',
      customer_phone: invoice.customer_phone ?? '',
      customer_email: invoice.customer_email ?? '',
      customer_gstin: invoice.customer_gstin ?? '',
    })
  }, [invoice.id, invoice.customer_name, invoice.customer_phone, invoice.customer_email, invoice.customer_gstin])

  const guestPayload = () => Object.fromEntries(Object.entries(guest).map(([key, entry]) => [key, entry.trim() || null]))

  const saveGuest = async () => {
    if (busy) return
    onBusy(true)
    try {
      onInvoiceChange(unwrap(await api.patch<ApiEnvelope<Invoice>>(`/api/v1/invoices/${invoice.id}/guest`, guestPayload())))
      onNotice('Customer details saved. Billing was not blocked.')
    } catch (requestError) { onError(errorMessage(requestError)) } finally { onBusy(false) }
  }

  const pay = async () => {
    if (busy) return
    const valid = payments.filter((payment) => value(payment.amount) > 0)
    if (!valid.length) return onError('Enter at least one payment amount.')
    if (valid.reduce((sum, payment) => sum + value(payment.amount), 0) > value(invoice.balance_amount)) return onError('Split payments cannot exceed the balance.')
    onBusy(true)
    try {
      let updated = invoice
      for (const payment of valid) updated = unwrap(await api.post<ApiEnvelope<Invoice>>(`/api/v1/invoices/${invoice.id}/payments`, payment, { headers: idempotencyHeaders() }))
      onInvoiceChange(updated)
      setPaymentOpen(false)
      onNotice(updated.payment_status === 'paid' ? 'Payment complete. Table is now available.' : 'Partial payment recorded.')
    } catch (requestError) { onError(errorMessage(requestError)) } finally { onBusy(false) }
  }

  const voidInvoice = async (reason: string) => {
    onBusy(true)
    try {
      await api.post<ApiEnvelope<Invoice>>(`/api/v1/invoices/${invoice.id}/void`, { reason })
      setVoidOpen(false)
      onNotice('Bill voided. The reason remains in the audit trail.')
      onVoided?.()
    } catch (requestError) { onError(errorMessage(requestError)) } finally { onBusy(false) }
  }

  const reopenBill = async (reason: string) => {
    onBusy(true)
    try {
      const updated = unwrap(await api.post<ApiEnvelope<DiningSession>>(`/api/v1/invoices/${invoice.id}/reopen`, { reason }))
      setReopenOpen(false)
      onNotice('Bill reopened. Remove items here, add items from Orders, apply discount, then create the bill again.')
      onReopened?.(updated)
    } catch (requestError) { onError(errorMessage(requestError)) } finally { onBusy(false) }
  }

  const refund = async () => {
    if (!refundPayment || refundPayment.reason.trim().length < 3) return
    onBusy(true)
    try {
      onInvoiceChange(unwrap(await api.post<ApiEnvelope<Invoice>>(`/api/v1/invoices/${invoice.id}/payments/${refundPayment.id}/refund`, { amount: value(refundPayment.amount), reason: refundPayment.reason.trim() })))
      setRefundPayment(null)
      onNotice('Refund recorded.')
    } catch (requestError) { onError(errorMessage(requestError)) } finally { onBusy(false) }
  }

  const printReceipt = (bill: Invoice, duplicate: boolean) => {
    if (!session) return
    const printed = printThermalReceipt({
      hotel: session.hotel,
      outlet: session.outlets.find((outlet) => outlet.id === activeOutletId) ?? session.outlets[0],
      cashier: session.user.name,
      tableName,
      serviceLabel,
      invoice: bill,
      items: bill.items ?? items,
      duplicate,
    })
    if (!printed) onError('The print dialog could not be opened. Try Print again.')
    else onNotice(duplicate ? 'Duplicate receipt sent to the printer.' : 'Bill sent to the printer.')
  }

  const reprint = async () => {
    printReceipt({ ...invoice, reprint_count: (invoice.reprint_count ?? 0) + 1 }, true)
    try {
      const reprinted = unwrap(await api.post<ApiEnvelope<Invoice>>(`/api/v1/invoices/${invoice.id}/reprint`))
      onInvoiceChange({ ...invoice, reprint_count: reprinted.reprint_count })
    } catch (requestError) { onError(errorMessage(requestError)) }
  }

  const renderBillLine = (item: (typeof items)[number]) => (
    <div className="invoice-line" key={item.id}>
      <span>{item.quantity} × {item.item_name}{item.status ? <small>{readable(item.status)}</small> : null}</span>
      <strong>{money(item.line_total, currency)}</strong>
    </div>
  )

  return <>
    <div className="invoice-heading"><div><h2>{invoice.invoice_number}</h2><span>{readable(invoice.status)} · {readable(invoice.payment_status)}{tableName ? ` · ${tableName}` : ''}</span></div><div className="receipt-actions"><button type="button" className="button button-secondary" onClick={() => void reprint()}><Printer size={15} />Print</button>{can(session, 'billing.create') && invoice.status === 'issued' && value(invoice.paid_amount) === 0 && <button type="button" className="button button-secondary" onClick={() => setReopenOpen(true)}><Undo2 size={15} />Correct bill</button>}{can(session, 'billing.void') && invoice.status === 'issued' && <button type="button" className="button button-secondary" onClick={() => setVoidOpen(true)}><Undo2 size={15} />Void</button>}</div></div>
    <div className="invoice-lines">
      <div className="bill-line-section">
        {internalItems.length > 0 && customerItems.length > 0 && <h3>Customer bill</h3>}
        {customerItems.map(renderBillLine)}
        {customerItems.length > 0 && <div className="bill-line-subtotal"><span>Items total</span><strong>{money(sectionTotal(customerItems), currency)}</strong></div>}
        {!customerItems.length && !internalItems.length && <p className="bill-empty-lines">No items on this bill yet.</p>}
      </div>
      {internalItems.length > 0 && <div className="bill-line-section internal-bill-section">
        <h3>Not on customer bill</h3>
        {internalItems.map(renderBillLine)}
        <div className="bill-line-subtotal"><span>Internal total</span><strong>{money(sectionTotal(internalItems), currency)}</strong></div>
      </div>}
    </div>
    {invoice.status !== 'voided' && can(session, 'billing.create') && <div className="discount-row guest-details"><label className="field"><span>Guest name (optional)</span><input value={guest.customer_name} onChange={(event) => setGuest((currentGuest) => ({ ...currentGuest, customer_name: event.target.value }))} placeholder="Not required to bill" /></label><label className="field"><span>Phone (optional)</span><input value={guest.customer_phone} onChange={(event) => setGuest((currentGuest) => ({ ...currentGuest, customer_phone: event.target.value }))} /></label><label className="field"><span>Email (optional)</span><input type="email" value={guest.customer_email} onChange={(event) => setGuest((currentGuest) => ({ ...currentGuest, customer_email: event.target.value }))} /></label><label className="field"><span>GSTIN (optional)</span><input value={guest.customer_gstin} onChange={(event) => setGuest((currentGuest) => ({ ...currentGuest, customer_gstin: event.target.value }))} /></label><button type="button" className="button button-secondary" disabled={busy} onClick={() => void saveGuest()}>Save guest details</button></div>}
    <div className="invoice-total"><span>Total</span><strong>{money(invoice.total_amount, currency)}</strong>{value(invoice.discount_amount) > 0 ? <span>Discount {money(invoice.discount_amount, currency)}</span> : null}<span>Paid {money(invoice.paid_amount, currency)} · Balance {money(invoice.balance_amount, currency)}</span>{invoice.charged_to?.name ? <span>On account: {invoice.charged_to.name}</span> : null}</div>
    <div className="payment-summary">{invoice.payments?.map((payment) => <div key={payment.id}><span>{readable(payment.method)}<small>{new Date(payment.paid_at).toLocaleString()}</small></span><strong>{money(payment.amount, currency)}</strong>{can(session, 'billing.refund') && payment.type !== 'refund' && Number(payment.amount) - Number(payment.refunded_amount ?? 0) > 0 && <button type="button" className="text-danger" onClick={() => setRefundPayment({ id: payment.id, amount: (Number(payment.amount) - Number(payment.refunded_amount ?? 0)).toFixed(2), refunded: payment.refunded_amount ?? '0', reason: '' })}>Refund remaining {(Number(payment.amount) - Number(payment.refunded_amount ?? 0)).toFixed(2)}</button>}</div>)}</div>
    {invoice.status === 'issued' && value(invoice.balance_amount) > 0 && <button type="button" className="button button-primary button-full" onClick={() => { setPayments([{ method: 'cash', amount: invoice.balance_amount, reference_number: '' }]); setPaymentOpen(true) }}><CreditCard size={16} />Take payment</button>}
    <Modal open={paymentOpen} title="Split payment" onClose={() => !busy && setPaymentOpen(false)}><p className="modal-intro">Balance: <strong>{money(invoice.balance_amount, currency)}</strong></p><div className="split-payment-list">{payments.map((payment, index) => <div className="split-payment" key={index}><label className="field"><span>Method</span><select value={payment.method} onChange={(event) => setPayments((currentPayments) => currentPayments.map((entry, entryIndex) => entryIndex === index ? { ...entry, method: event.target.value as 'cash' | 'card' | 'upi' } : entry))}><option value="cash">Cash</option><option value="card">Card</option><option value="upi">UPI</option></select></label><label className="field"><span>Amount</span><input type="number" min="0" value={payment.amount} onChange={(event) => setPayments((currentPayments) => currentPayments.map((entry, entryIndex) => entryIndex === index ? { ...entry, amount: event.target.value } : entry))} /></label><label className="field"><span>Reference (optional)</span><input value={payment.reference_number} onChange={(event) => setPayments((currentPayments) => currentPayments.map((entry, entryIndex) => entryIndex === index ? { ...entry, reference_number: event.target.value } : entry))} /></label>{payments.length > 1 && <button type="button" className="remove-payment" onClick={() => setPayments((currentPayments) => currentPayments.filter((_, entryIndex) => entryIndex !== index))}><X size={16} /></button>}</div>)}</div><div className="form-action-row"><button type="button" className="button button-secondary" onClick={() => setPayments((currentPayments) => [...currentPayments, { method: 'cash', amount: '', reference_number: '' }])}><Plus size={15} />Split payment</button><button type="button" className="button button-primary" disabled={busy} onClick={() => void pay()}>{busy ? 'Saving…' : 'Confirm payment'}</button></div></Modal>
    <PromptDialog open={voidOpen} title="Void invoice" description="This never deletes the bill. It creates a permanent void record that stays in the audit trail." label="Required reason" type="textarea" minLength={3} confirmLabel="Void bill" busy={busy} onClose={() => !busy && setVoidOpen(false)} onConfirm={(reason) => void voidInvoice(reason)} />
    <PromptDialog open={reopenOpen} title="Correct this bill?" description="The current invoice is kept as voided. The table or parcel stays open so you can exclude items, add items, apply discount, and create the bill again." label="Required reason" type="textarea" minLength={3} confirmLabel="Reopen bill" busy={busy} onClose={() => !busy && setReopenOpen(false)} onConfirm={(reason) => void reopenBill(reason)} />
    <Modal open={Boolean(refundPayment)} title="Refund payment" description="Refunds cannot exceed the remaining amount on this payment." onClose={() => !busy && setRefundPayment(null)}>{refundPayment && <><p className="modal-intro">You can refund up to the remaining amount{refundPayment.refunded && Number(refundPayment.refunded) > 0 ? ` (${refundPayment.refunded} already refunded)` : ''}.</p><label className="field"><span>Refund amount</span><input type="number" min="0.01" step="0.01" max={refundPayment.amount} value={refundPayment.amount} onChange={(event) => setRefundPayment({ ...refundPayment, amount: event.target.value })} /></label><label className="field"><span>Required reason</span><textarea rows={3} value={refundPayment.reason} onChange={(event) => setRefundPayment({ ...refundPayment, reason: event.target.value })} /></label><div className="form-action-row"><button type="button" className="button button-secondary button-touch" onClick={() => setRefundPayment(null)}>Cancel</button><button type="button" className="button button-primary button-touch" disabled={busy || refundPayment.reason.trim().length < 3} onClick={() => void refund()}>{busy ? 'Saving…' : 'Confirm refund'}</button></div></>}</Modal>
  </>
}
