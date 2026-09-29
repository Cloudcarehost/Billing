import { ArrowLeft, ChevronLeft, ChevronRight, History, Search } from 'lucide-react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { InvoiceReceiptPanel } from '../components/InvoiceReceiptPanel'
import { Toast } from '../components/ui/Feedback'
import { useAuth } from '../features/auth/AuthContext'
import { api, errorMessage } from '../lib/api'
import type { ApiEnvelope, Invoice, Paginated } from '../types/api'
import { isParcelTable, money, readable, unwrap } from './opsShared'

type InvoiceRow = Invoice & {
  table_name?: string | null
  service_type?: 'dine_in' | 'parcel'
  payment_methods?: string[]
  creator?: { id: number; name: string } | null
  business_date?: string
}

const PAGE_SIZE = 20
const todayIso = (value?: string | null) => value ?? new Date().toISOString().slice(0, 10)
const shiftDate = (value: string, days: number) => {
  const date = new Date(`${value}T00:00:00`)
  date.setDate(date.getDate() + days)
  return date.toISOString().slice(0, 10)
}

export function PreviousBillsPage() {
  const { invoiceId } = useParams()
  return invoiceId ? <PreviousBillView invoiceId={Number(invoiceId)} /> : <PreviousBillList />
}

function PreviousBillList() {
  const { session } = useAuth()
  const [params, setParams] = useSearchParams()
  const navigate = useNavigate()
  const today = todayIso(session?.hotel.current_business_date)
  const range = params.get('range') === 'all' || params.get('range') === 'week' || params.get('range') === 'today' ? params.get('range')! : (params.get('from') || params.get('to') ? 'custom' : 'today')
  const from = params.get('from') ?? (range === 'all' ? '' : range === 'week' ? shiftDate(today, -6) : today)
  const to = params.get('to') ?? (range === 'all' ? '' : today)
  const query = params.get('q') ?? ''
  const status = params.get('status') ?? ''
  const paymentStatus = params.get('payment_status') ?? ''
  const page = Math.max(1, Number(params.get('page') || 1))
  const [draftQuery, setDraftQuery] = useState(query)
  const [rows, setRows] = useState<InvoiceRow[]>([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: PAGE_SIZE, total: 0 })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const currency = session?.hotel.currency_code
  const search = useMemo(() => {
    const next = new URLSearchParams()
    if (query) next.set('q', query)
    if (range === 'all') next.set('range', 'all')
    else {
      if (from) next.set('from', from)
      if (to) next.set('to', to)
      if (range === 'week' || range === 'today') next.set('range', range)
    }
    if (status) next.set('status', status)
    if (paymentStatus) next.set('payment_status', paymentStatus)
    if (page > 1) next.set('page', String(page))
    return next.toString()
  }, [from, page, paymentStatus, query, range, status, to])

  const update = useCallback((patch: Record<string, string | null>, resetPage = true) => {
    setParams((current) => {
      const next = new URLSearchParams(current)
      Object.entries(patch).forEach(([key, value]) => { if (value) next.set(key, value); else next.delete(key) })
      if (resetPage) next.delete('page')
      return next
    }, { replace: true })
  }, [setParams])

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const queryParams: Record<string, string | number> = { per_page: PAGE_SIZE, page }
      if (query) queryParams.q = query
      if (from) queryParams.from = from
      if (to) queryParams.to = to
      if (status) queryParams.status = status
      if (paymentStatus) queryParams.payment_status = paymentStatus
      const response = await api.get<Paginated<InvoiceRow>>('/api/v1/invoices', { params: queryParams })
      setRows(unwrap(response) ?? response.data.data)
      setMeta(response.data.meta)
    } catch (requestError) { setError(errorMessage(requestError)) } finally { setLoading(false) }
  }, [from, page, paymentStatus, query, status, to])

  useEffect(() => { const task = window.setTimeout(() => void load(), 0); return () => window.clearTimeout(task) }, [load])
  useEffect(() => { setDraftQuery(query) }, [query])
  useEffect(() => {
    if (draftQuery === query) return
    const task = window.setTimeout(() => update({ q: draftQuery.trim() || null }), 300)
    return () => window.clearTimeout(task)
  }, [draftQuery, query, update])

  const setRange = (next: 'today' | 'week' | 'all') => {
    if (next === 'all') update({ range: 'all', from: null, to: null })
    else if (next === 'week') update({ range: 'week', from: shiftDate(today, -6), to: today })
    else update({ range: 'today', from: today, to: today })
  }

  return <section className="previous-bills-page">
    <div className="page-heading"><div><p className="eyebrow">COUNTER BILLING</p><h1>Previous bills</h1><p>Find a settled or unpaid bill, then open the same receipt used at the counter.</p></div><Link className="button button-secondary" to="/app/billing">Back to live billing</Link></div>
    <section className="report-filter-bar previous-bills-filters">
      <label className="catalog-search previous-bills-search"><Search size={17} /><input value={draftQuery} onChange={(event) => setDraftQuery(event.target.value)} placeholder="Search invoice, guest, phone, or table" /></label>
      <label className="field"><span>From</span><input type="date" value={from} onChange={(event) => update({ range: null, from: event.target.value || null, to: to || event.target.value || null })} /></label>
      <label className="field"><span>To</span><input type="date" value={to} onChange={(event) => update({ range: null, to: event.target.value || null, from: from || event.target.value || null })} /></label>
      <div className="status-filters" role="group" aria-label="Date range">
        <button type="button" className={range === 'today' ? 'selected' : ''} onClick={() => setRange('today')}>Today</button>
        <button type="button" className={range === 'week' ? 'selected' : ''} onClick={() => setRange('week')}>7 days</button>
        <button type="button" className={range === 'all' ? 'selected' : ''} onClick={() => setRange('all')}>All</button>
      </div>
    </section>
    <div className="status-filters previous-bills-chips" role="group" aria-label="Bill status">
      {[['All', ''], ['Issued', 'issued'], ['Voided', 'voided']].map(([label, value]) => <button type="button" key={label} className={status === value ? 'selected' : ''} onClick={() => update({ status: value || null })}>{label}</button>)}
    </div>
    <div className="status-filters previous-bills-chips" role="group" aria-label="Payment status">
      {[['All payments', ''], ['Paid', 'paid'], ['Partial', 'partial'], ['Unpaid', 'unpaid']].map(([label, value]) => <button type="button" key={label} className={paymentStatus === value ? 'selected' : ''} onClick={() => update({ payment_status: value || null })}>{label}</button>)}
    </div>
    <section className="data-card">
      <div className="data-card-heading"><History size={19} /><h2>Generated bills</h2><span>{loading ? 'Loading…' : `${meta.total} bill${meta.total === 1 ? '' : 's'}`}</span></div>
      <div className="data-table"><table className="report-table previous-bills-table"><thead><tr><th>Invoice</th><th>Time</th><th>Table</th><th>Guest</th><th>Status</th><th>Payment</th><th>Methods</th><th>Total</th><th>Balance</th><th></th></tr></thead>
        <tbody>
          {rows.map((row) => {
            const due = row.status === 'issued' && row.payment_status !== 'paid'
            return <tr key={row.id} className={due ? 'bill-due-row' : row.status === 'voided' ? 'bill-voided-row' : ''} onClick={() => navigate(`/app/billing/history/${row.id}${search ? `?${search}` : ''}`)}>
              <td><strong>{row.invoice_number}</strong>{row.creator?.name ? <small>{row.creator.name}</small> : null}</td>
              <td>{row.billed_at ? new Date(row.billed_at).toLocaleString() : row.business_date ?? '—'}</td>
              <td>{row.table_name || '—'}<small>{isParcelTable({ service_type: row.service_type }) ? 'Parcel' : 'Dine in'}</small></td>
              <td>{row.charged_to?.name || row.customer_name || '—'}{row.customer_phone ? <small>{row.customer_phone}</small> : null}</td>
              <td><span className={`movement-type ${row.status === 'voided' ? 'negative' : ''}`}>{readable(row.status)}</span></td>
              <td><span className={`status-badge ${row.payment_status === 'paid' ? 'status-green' : row.payment_status === 'partial' ? 'status-amber' : 'status-red'}`}>{readable(row.payment_status)}</span></td>
              <td>{(row.payment_methods ?? []).map(readable).join(', ') || '—'}</td>
              <td>{money(row.total_amount, currency)}</td>
              <td className={due ? 'negative' : ''}>{money(row.balance_amount, currency)}</td>
              <td><button type="button" className="button button-secondary" onClick={(event) => { event.stopPropagation(); navigate(`/app/billing/history/${row.id}${search ? `?${search}` : ''}`) }}>View</button></td>
            </tr>
          })}
          {!loading && !rows.length && <tr><td colSpan={10} className="empty-row">No previous bills match this search.</td></tr>}
        </tbody>
      </table></div>
      {meta.last_page > 1 && <nav className="money-pager" aria-label="Previous bills pages">
        <button type="button" className="button button-secondary" disabled={meta.current_page <= 1} onClick={() => update({ page: String(meta.current_page - 1) }, false)}><ChevronLeft size={16} />Prev</button>
        <span>Page {meta.current_page} of {meta.last_page}</span>
        <button type="button" className="button button-secondary" disabled={meta.current_page >= meta.last_page} onClick={() => update({ page: String(meta.current_page + 1) }, false)}>Next<ChevronRight size={16} /></button>
      </nav>}
    </section>
    <Toast message={error} tone="error" onDismiss={() => setError('')} />
  </section>
}

function PreviousBillView({ invoiceId }: { invoiceId: number }) {
  const location = useLocation()
  const navigate = useNavigate()
  const [invoice, setInvoice] = useState<Invoice | null>(null)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const listPath = `/app/billing/history${location.search}`
  const tableName = invoice?.dining_session?.display_name || invoice?.dining_session?.dining_table?.name || invoice?.table_name || 'Table'
  const serviceLabel = isParcelTable(invoice?.dining_session?.dining_table) ? 'Parcel' : 'Dine In'

  const load = useCallback(async () => {
    try { setInvoice(unwrap(await api.get<ApiEnvelope<Invoice>>(`/api/v1/invoices/${invoiceId}`))) } catch (requestError) { setError(errorMessage(requestError)) }
  }, [invoiceId])
  useEffect(() => { const task = window.setTimeout(() => void load(), 0); return () => window.clearTimeout(task) }, [load])

  return <section className="billing-page previous-bill-page">
    <div className="page-heading"><div><p className="eyebrow">COUNTER BILLING</p><h1>{invoice?.invoice_number ?? 'Bill'}</h1><p>Same receipt as after take payment — print, collect a remaining balance, or record a correction.</p></div><Link className="button button-secondary" to={listPath}><ArrowLeft size={15} />Back to previous bills</Link></div>
    <main className="invoice-panel receipt-panel previous-bill-panel">{!invoice && !error ? <p className="empty-row">Loading bill…</p> : invoice ? <InvoiceReceiptPanel invoice={invoice} busy={busy} tableName={tableName} serviceLabel={serviceLabel} onBusy={setBusy} onInvoiceChange={setInvoice} onNotice={setNotice} onError={setError} onReopened={() => navigate('/app/billing')} onVoided={() => navigate(listPath)} /> : <p className="empty-row">This bill could not be opened.</p>}</main>
    <Toast message={notice || error} tone={error ? 'error' : 'success'} onDismiss={() => { setNotice(''); setError('') }} />
  </section>
}
