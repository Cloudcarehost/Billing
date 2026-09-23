import { Banknote, ChevronLeft, ChevronRight, CirclePlus, HandCoins, Pencil, RefreshCw, Trash2, Wallet, WalletCards } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import type { FormEvent, ReactNode } from 'react'
import { Modal, ConfirmDialog } from '../components/ui/Feedback'
import { useAuth } from '../features/auth/AuthContext'
import { can } from '../features/auth/permissions'
import { api, errorMessage } from '../lib/api'
import type { ApiEnvelope, FinanceSummary, LedgerEntry, StandingCost } from '../types/api'

const money = (value: string | number | undefined, currency = 'INR') => new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 2 }).format(Number(value ?? 0))
const cycleLabel = (cycle: string) => cycle === 'daily' ? 'Daily' : cycle === 'weekly' ? 'Weekly' : 'Monthly'
const iso = (date: Date) => {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}
const addDays = (date: Date, days: number) => {
  const next = new Date(date)
  next.setDate(next.getDate() + days)
  return next
}
const rangeForPeriod = (period: string, today: string): [string, string] => {
  const day = new Date(`${today}T00:00:00`)
  if (Number.isNaN(day.getTime()) || period === 'today') return [today, today]
  if (period === 'week') {
    const weekday = day.getDay()
    const start = addDays(day, weekday === 0 ? -6 : 1 - weekday)
    return [iso(start), iso(addDays(start, 6))]
  }
  if (period === 'month') return [iso(new Date(day.getFullYear(), day.getMonth(), 1)), iso(new Date(day.getFullYear(), day.getMonth() + 1, 0))]
  if (period === 'year') return [`${day.getFullYear()}-01-01`, `${day.getFullYear()}-12-31`]
  return [today, today]
}
const payOn = (value?: string | null) => {
  if (!value) return ''
  const date = new Date(`${value}T00:00:00`)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })
}
const payOnLabel = (cycle: string, value?: string | null) => {
  if (!value) return ''
  const date = new Date(`${value}T00:00:00`)
  if (Number.isNaN(date.getTime())) return value
  if (cycle === 'monthly') return `Pay on the ${date.getDate()} each month`
  if (cycle === 'weekly') return `Pay on ${date.toLocaleDateString('en-IN', { weekday: 'long' })}s`
  return `Pay on ${payOn(value)}`
}
const entryKind = (entry: LedgerEntry) => {
  if (entry.source === 'staff') return 'Salary'
  if (entry.source === 'standing') return 'Fixed bill'
  return entry.type === 'income' ? 'Income' : 'Expense'
}
const isLedger = (entry: LedgerEntry) => entry.editable === true && typeof entry.id === 'number'
const CATEGORIES = ['Groceries', 'Construction', 'Rent', 'Electricity', 'Other']
const ENTRY_PAGE_SIZE = 5

function Heading({ eyebrow, title, text, action }: { eyebrow: string; title: string; text: string; action?: ReactNode }) {
  return <section className="page-heading"><div><p className="eyebrow">{eyebrow}</p><h1>{title}</h1><p>{text}</p></div>{action}</section>
}
function Notice({ error, success }: { error?: string; success?: string }) {
  return <>{error && <p className="form-error form-notice">{error}</p>}{success && <p className="form-success form-notice">{success}</p>}</>
}

type EntryForm = LedgerEntry | 'new' | null
type BillForm = StandingCost | 'new' | null

export function MoneyPage() {
  const { session } = useAuth()
  const today = session?.hotel.current_business_date ?? new Date().toISOString().slice(0, 10)
  const [period, setPeriod] = useState('month')
  const [from, setFrom] = useState(() => rangeForPeriod('month', today)[0])
  const [to, setTo] = useState(() => rangeForPeriod('month', today)[1])
  const [data, setData] = useState<FinanceSummary | null>(null)
  const [bills, setBills] = useState<StandingCost[]>([])
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [formError, setFormError] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [entryForm, setEntryForm] = useState<EntryForm>(null)
  const [billForm, setBillForm] = useState<BillForm>(null)
  const [deleting, setDeleting] = useState<LedgerEntry | null>(null)
  const [entryType, setEntryType] = useState<'expense' | 'income'>('expense')
  const [category, setCategory] = useState('Groceries')
  const [entryPage, setEntryPage] = useState(1)
  const requestSeq = useRef(0)
  const mayManage = can(session, 'finance.manage')
  const currency = session?.hotel.currency_code ?? 'INR'
  const customRange = period === 'custom'
  const query = customRange ? (from && to ? `from=${from}&to=${to}` : '') : `period=${period}`

  const load = useCallback(async () => {
    if (!query) return
    const seq = ++requestSeq.current
    setLoading(true)
    setError('')
    try {
      const [summary, billData] = await Promise.all([
        api.get<ApiEnvelope<FinanceSummary>>(`/api/v1/finance/summary?${query}`),
        api.get<ApiEnvelope<StandingCost[]>>('/api/v1/finance/standing-costs'),
      ])
      if (seq !== requestSeq.current) return
      setData(summary.data.data)
      setFrom(summary.data.data.period.from)
      setTo(summary.data.data.period.to)
      setBills(billData.data.data)
      setEntryPage(1)
    } catch (err) {
      if (seq !== requestSeq.current) return
      setError(errorMessage(err))
    } finally {
      if (seq === requestSeq.current) setLoading(false)
    }
  }, [query])
  useEffect(() => { const task = window.setTimeout(() => { void load() }, 0); return () => window.clearTimeout(task) }, [load])

  function selectPeriod(next: string) {
    setPeriod(next)
    setSuccess('')
    if (next === 'custom') return
    const [nextFrom, nextTo] = rangeForPeriod(next, today)
    setFrom(nextFrom)
    setTo(nextTo)
  }

  function openEntry(next: EntryForm) {
    const existing = next && next !== 'new' ? next : null
    if (existing && !isLedger(existing)) return
    setEntryType(existing?.type ?? 'expense')
    setCategory(existing?.category ?? 'Groceries')
    setFormError('')
    setSuccess('')
    setEntryForm(next)
  }

  async function saveEntry(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const values = new FormData(event.currentTarget)
    const payload = {
      type: entryType,
      category: category.trim() || 'Other',
      amount: Number(values.get('amount')),
      comment: String(values.get('comment') || '') || null,
      occurred_on: String(values.get('occurred_on')),
    }
    setFormError('')
    setSaving(true)
    try {
      if (entryForm && entryForm !== 'new') await api.put(`/api/v1/finance/entries/${entryForm.id}`, payload)
      else await api.post('/api/v1/finance/entries', payload)
      setEntryForm(null)
      setSuccess(entryType === 'income' ? 'Income saved.' : 'Expense saved.')
      await load()
    } catch (err) {
      setFormError(errorMessage(err))
    } finally {
      setSaving(false)
    }
  }

  async function saveBill(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const values = new FormData(event.currentTarget)
    const payload = {
      name: String(values.get('name')),
      amount: Number(values.get('amount')),
      pay_cycle: String(values.get('pay_cycle')),
      due_on: String(values.get('due_on') || '') || null,
      is_active: values.has('is_active'),
    }
    setFormError('')
    setSaving(true)
    try {
      if (billForm && billForm !== 'new') await api.put(`/api/v1/finance/standing-costs/${billForm.id}`, payload)
      else await api.post('/api/v1/finance/standing-costs', payload)
      setBillForm(null)
      setSuccess('Fixed bill saved.')
      await load()
    } catch (err) {
      setFormError(errorMessage(err))
    } finally {
      setSaving(false)
    }
  }

  async function deleteEntry() {
    if (!deleting || !isLedger(deleting)) return
    setError('')
    try {
      await api.delete(`/api/v1/finance/entries/${deleting.id}`)
      setSuccess('Entry deleted.')
      setDeleting(null)
      await load()
    } catch (err) {
      setError(errorMessage(err))
      setDeleting(null)
    }
  }

  const remainingNegative = Number(data?.remaining ?? 0) < 0
  const editingEntry = entryForm && entryForm !== 'new' ? entryForm : null
  const editingBill = billForm && billForm !== 'new' ? billForm : null
  const entries = data?.entries ?? []
  const entryPages = Math.max(1, Math.ceil(entries.length / ENTRY_PAGE_SIZE))
  const currentEntryPage = Math.min(entryPage, entryPages)
  const pagedEntries = entries.slice((currentEntryPage - 1) * ENTRY_PAGE_SIZE, currentEntryPage * ENTRY_PAGE_SIZE)
  const entryFrom = entries.length ? (currentEntryPage - 1) * ENTRY_PAGE_SIZE + 1 : 0
  const entryTo = Math.min(currentEntryPage * ENTRY_PAGE_SIZE, entries.length)

  return <div className="money-page">
    <Heading eyebrow="MONEY" title="Earnings and standing costs" text="Billed totals stay in the top card. Rent, salaries, and extra spend appear as deducted entries when their pay date falls in this period." action={<div className="heading-actions">{mayManage && <button className="button button-primary" type="button" onClick={() => openEntry('new')}><CirclePlus size={16} />Add expense</button>}{mayManage && <button className="button button-secondary" type="button" onClick={() => { setBillForm('new'); setFormError(''); setSuccess('') }}><Banknote size={16} />Fixed bill</button>}</div>} />
    <section className="report-filter-bar">
      <label className="field"><span>Period</span><select value={period} onChange={(event) => selectPeriod(event.target.value)} aria-label="Period"><option value="today">Today</option><option value="week">This week</option><option value="month">This month</option><option value="year">This year</option><option value="custom">Custom dates</option></select></label>
      <label className="field"><span>From</span><input type="date" value={from} disabled={!customRange} readOnly={!customRange} onChange={(event) => { if (!customRange) return; setFrom(event.target.value) }} /></label>
      <label className="field"><span>To</span><input type="date" value={to} disabled={!customRange} readOnly={!customRange} onChange={(event) => { if (!customRange) return; setTo(event.target.value) }} /></label>
      <button className="button button-secondary" type="button" disabled={loading} onClick={() => void load()}><RefreshCw size={15} className={loading ? 'icon-spin' : ''} />{loading ? 'Refreshing…' : 'Refresh'}</button>
    </section>
    <Notice error={error} success={success} />
    {loading && !data ? <section className="dashboard-metrics">{['one', 'two', 'three', 'four'].map((key) => <div key={key} className="loading-skeleton metric-skeleton" />)}</section> : data && <>
      <section className="dashboard-metrics">
        <article className="dashboard-metric green"><span><WalletCards size={18} /></span><small>Billed</small><strong>{money(data.billed, currency)}</strong></article>
        <article className="dashboard-metric blue"><span><Banknote size={18} /></span><small>Other income</small><strong>{money(data.other_income, currency)}</strong></article>
        <article className="dashboard-metric amber"><span><HandCoins size={18} /></span><small>Expenses</small><strong>{money(data.expenses, currency)}</strong></article>
        <article className={`dashboard-metric ${remainingNegative ? 'red' : 'green'}`}><span><Wallet size={18} /></span><small>Remaining</small><strong className={remainingNegative ? 'negative' : ''}>{money(data.remaining, currency)}</strong></article>
      </section>
      <section className="report-two-column">
        <article className="data-card">
          <div className="data-card-heading"><Wallet size={19} /><h2>On the books this period</h2><span>{data.standing.length}</span></div>
          <div className="data-list">{data.standing.map((line) => <div className="data-row" key={line.key}><span className="row-icon"><Wallet size={17} /></span><div><strong>{line.name}</strong><small>{cycleLabel(line.pay_cycle)} · {money(line.rate, currency)} · {line.detail}</small></div><b>{money(line.amount, currency)}</b></div>)}{!data.standing.length && <p className="empty-row">No staff salary or fixed bill is due in this period.</p>}</div>
        </article>
        <article className="data-card">
          <div className="data-card-heading"><Banknote size={19} /><h2>Fixed bills</h2>{mayManage && <button className="button button-secondary" type="button" onClick={() => { setBillForm('new'); setFormError('') }}>Add</button>}</div>
          <div className="data-list">{bills.map((bill) => <div className="data-row" key={bill.id}><span className="row-icon"><Banknote size={17} /></span><div><strong>{bill.name}</strong><small>{cycleLabel(bill.pay_cycle)} · {money(bill.amount, currency)}{bill.due_on ? ` · ${payOnLabel(bill.pay_cycle, bill.due_on)}` : ''}</small></div><span className={`status-badge ${bill.is_active ? 'status-green' : 'status-red'}`}>{bill.is_active ? 'Active' : 'Off'}</span>{mayManage && <button className="row-action" type="button" title={`Edit ${bill.name}`} onClick={() => { setBillForm(bill); setFormError('') }}><Pencil size={16} /></button>}</div>)}{!bills.length && <p className="empty-row">Add plot rent, light, or other standing bills.</p>}</div>
        </article>
      </section>
      <section className="data-card money-entries">
        <div className="data-card-heading"><HandCoins size={19} /><h2>Entries</h2><span>{entries.length ? `${entryFrom}–${entryTo} of ${entries.length}` : 0}</span></div>
        <div className="data-list">{pagedEntries.map((entry) => <div className="data-row money-entry-row" key={String(entry.id)}><div><strong>{entry.category}</strong><small>{payOn(entry.occurred_on)} · {entryKind(entry)}{entry.comment ? ` · ${entry.comment}` : ''}</small></div><b className={entry.type === 'income' ? 'positive' : 'negative'}>{entry.type === 'income' ? '+' : '−'}{money(entry.amount, currency)}</b>{mayManage && isLedger(entry) && <span className="row-actions"><button className="row-action" type="button" title="Edit entry" onClick={() => openEntry(entry)}><Pencil size={18} /></button><button className="row-action danger" type="button" title="Delete entry" onClick={() => setDeleting(entry)}><Trash2 size={18} /></button></span>}</div>)}{!entries.length && <p className="empty-row">No deducted or added entries in this period.</p>}</div>
        {entries.length > ENTRY_PAGE_SIZE && <nav className="money-pager" aria-label="Entries pages">
          <button type="button" className="button button-secondary" disabled={currentEntryPage <= 1} onClick={() => setEntryPage(currentEntryPage - 1)}><ChevronLeft size={16} />Prev</button>
          <span>Page {currentEntryPage} of {entryPages}</span>
          <button type="button" className="button button-secondary" disabled={currentEntryPage >= entryPages} onClick={() => setEntryPage(currentEntryPage + 1)}>Next<ChevronRight size={16} /></button>
        </nav>}
      </section>
    </>}
    <Modal open={entryForm !== null} title={editingEntry ? 'Edit entry' : 'Add expense'} description="Type the amount, pick what it was for, and add a short comment. Date defaults to today." onClose={() => !saving && setEntryForm(null)}>
      <form key={String(editingEntry?.id ?? 'new-entry')} className="compact-form money-form" onSubmit={saveEntry}>
        <div className="money-type-toggle" role="group" aria-label="Entry type">
          <button type="button" className={entryType === 'expense' ? 'selected' : ''} onClick={() => setEntryType('expense')}>Expense</button>
          <button type="button" className={entryType === 'income' ? 'selected' : ''} onClick={() => setEntryType('income')}>Other income</button>
        </div>
        <label className="field money-amount-field"><span>Amount</span><input name="amount" type="number" inputMode="decimal" min="0.01" step="0.01" required autoComplete="off" enterKeyHint="next" defaultValue={editingEntry?.amount ?? ''} placeholder="0.00" /></label>
        <div className="field"><span>What for</span>
          <div className="money-chips">{CATEGORIES.map((item) => <button type="button" key={item} className={category === item ? 'selected' : ''} onClick={() => setCategory(item)}>{item}</button>)}</div>
          <input name="category" value={category} onChange={(event) => setCategory(event.target.value)} required placeholder="Groceries, construction…" autoComplete="off" />
        </div>
        <label className="field"><span>Comment</span><textarea name="comment" rows={2} defaultValue={editingEntry?.comment ?? ''} placeholder="Bought oil, vegetables…" enterKeyHint="done" /></label>
        <label className="field"><span>Date</span><input name="occurred_on" type="date" required defaultValue={editingEntry?.occurred_on ?? today} /></label>
        {formError && <p className="form-error form-notice">{formError}</p>}
        <div className="form-action-row">
          <button className="button button-primary button-touch" type="submit" disabled={saving}>{saving ? 'Saving…' : (editingEntry ? 'Save' : 'Save expense')}</button>
          <button className="button button-secondary button-touch" type="button" disabled={saving} onClick={() => setEntryForm(null)}>Close</button>
        </div>
      </form>
    </Modal>
    <Modal open={billForm !== null} title={editingBill ? `Edit ${editingBill.name}` : 'Fixed bill'} description="Rent and light repeat on this pay date. Monthly bills deduct on that day each month, only when it falls in the selected period." onClose={() => !saving && setBillForm(null)}>
      <form key={editingBill?.id ?? 'new-bill'} className="compact-form money-form" onSubmit={saveBill}>
        <div className="form-grid">
          <label className="field"><span>Name</span><input name="name" required defaultValue={editingBill?.name ?? ''} placeholder="Plot rent" autoComplete="off" /></label>
          <label className="field money-amount-field"><span>Amount</span><input name="amount" type="number" inputMode="decimal" min="0.01" step="0.01" required defaultValue={editingBill?.amount ?? ''} /></label>
          <label className="field"><span>Cycle</span><select name="pay_cycle" defaultValue={editingBill?.pay_cycle ?? 'monthly'}><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option></select></label>
          <label className="field"><span>Pay on</span><input name="due_on" type="date" required defaultValue={editingBill?.due_on ?? today} /></label>
          <label className="check-row field-wide"><input type="checkbox" name="is_active" value="1" defaultChecked={editingBill?.is_active ?? true} />Active on the books</label>
        </div>
        {formError && <p className="form-error form-notice">{formError}</p>}
        <div className="form-action-row">
          <button className="button button-primary button-touch" type="submit" disabled={saving}>{saving ? 'Saving…' : (editingBill ? 'Save bill' : 'Add bill')}</button>
          <button className="button button-secondary button-touch" type="button" disabled={saving} onClick={() => setBillForm(null)}>Close</button>
        </div>
      </form>
    </Modal>
    <ConfirmDialog open={Boolean(deleting)} title="Delete this entry?" text="This removes the extra expense or other-income row. Standing salary and bills stay on the books." confirmLabel="Delete entry" onClose={() => setDeleting(null)} onConfirm={() => void deleteEntry()} />
  </div>
}
