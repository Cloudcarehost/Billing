import { Banknote, BookOpenCheck, Calculator, Check, ChevronLeft, ChevronRight, CircleAlert, CircleCheck, CirclePlus, CreditCard, HandCoins, Pencil, Plus, RefreshCw, Trash2, WalletCards, X } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import type { FormEvent, ReactNode } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Modal, ConfirmDialog } from '../components/ui/Feedback'
import { useAuth } from '../features/auth/AuthContext'
import { can } from '../features/auth/permissions'
import { api, errorMessage } from '../lib/api'
import type { ApiEnvelope, DailyMoneyRegisterOverview, DailyMoneyRegisterRow, FinanceSummary, LedgerEntry } from '../types/api'

const money = (value: string | number | undefined, currency = 'INR') => new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 2 }).format(Number(value ?? 0))
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
const entryKind = (entry: LedgerEntry) => {
  if (entry.source === 'staff') return 'Salary'
  if (entry.source === 'standing') return 'Fixed bill'
  return entry.type === 'income' ? 'Income' : 'Expense'
}
const firstName = (name?: string | null) => name?.trim().split(/\s+/)[0] ?? ''
const isLedger = (entry: LedgerEntry) => entry.editable === true && typeof entry.id === 'number'
const CATEGORIES = ['Groceries', 'Construction', 'Rent', 'Electricity', 'Other']
const ENTRY_PAGE_SIZE = 5
const REGISTER_PAGE_SIZE = 5
const registerDate = (value: string, today: string) => {
  const date = new Date(`${value}T00:00:00`)
  const formatted = Number.isNaN(date.getTime()) ? value : date.toLocaleDateString('en-GB')
  return value === today ? `${formatted} (Today)` : formatted
}
function categoryStorageKey(hotelId?: number) {
  return hotelId ? `money-what-for:${hotelId}` : ''
}
function hiddenCategoryStorageKey(hotelId?: number) {
  return hotelId ? `money-what-for-hidden:${hotelId}` : ''
}
function readStoredCategories(hotelId?: number): string[] {
  const key = categoryStorageKey(hotelId)
  if (!key) return []
  try {
    const raw = window.localStorage.getItem(key)
    const parsed = raw ? JSON.parse(raw) as unknown : []
    return Array.isArray(parsed) ? parsed.map((item) => String(item).trim()).filter(Boolean) : []
  } catch {
    return []
  }
}
function readHiddenCategories(hotelId?: number): string[] {
  const key = hiddenCategoryStorageKey(hotelId)
  if (!key) return []
  try {
    const raw = window.localStorage.getItem(key)
    const parsed = raw ? JSON.parse(raw) as unknown : []
    return Array.isArray(parsed) ? parsed.map((item) => String(item).trim()).filter(Boolean) : []
  } catch {
    return []
  }
}
function uniqueCategories(...groups: Array<Iterable<string>>): string[] {
  const seen = new Set<string>()
  const result: string[] = []
  for (const group of groups) {
    for (const item of group) {
      const name = item.trim()
      if (!name) continue
      const key = name.toLowerCase()
      if (seen.has(key)) continue
      seen.add(key)
      result.push(name)
    }
  }
  return result
}

function Heading({ eyebrow, title, text, action }: { eyebrow: string; title: string; text: string; action?: ReactNode }) {
  return <section className="page-heading"><div><p className="eyebrow">{eyebrow}</p><h1>{title}</h1><p>{text}</p></div>{action}</section>
}
function Notice({ error, success }: { error?: string; success?: string }) {
  return <>{error && <p className="form-error form-notice">{error}</p>}{success && <p className="form-success form-notice">{success}</p>}</>
}

type EntryForm = LedgerEntry | 'new' | null
type RegisterForm = DailyMoneyRegisterRow | 'new' | null

export function MoneyPage() {
  const { session, activeOutletId } = useAuth()
  const [searchParams] = useSearchParams()
  const today = session?.hotel.current_business_date ?? new Date().toISOString().slice(0, 10)
  const linkedFrom = searchParams.get('from') ?? ''
  const linkedTo = searchParams.get('to') ?? ''
  const linkedCategory = (searchParams.get('category') ?? '').trim()
  const linkedRange = Boolean(linkedFrom && linkedTo)
  const [period, setPeriod] = useState(() => linkedRange ? 'custom' : 'month')
  const [from, setFrom] = useState(() => linkedRange ? linkedFrom : rangeForPeriod('month', today)[0])
  const [to, setTo] = useState(() => linkedRange ? linkedTo : rangeForPeriod('month', today)[1])
  const [data, setData] = useState<FinanceSummary | null>(null)
  const [registerData, setRegisterData] = useState<DailyMoneyRegisterOverview | null>(null)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [formError, setFormError] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [entryForm, setEntryForm] = useState<EntryForm>(null)
  const [registerForm, setRegisterForm] = useState<RegisterForm>(null)
  const [deleting, setDeleting] = useState<LedgerEntry | null>(null)
  const [entryType, setEntryType] = useState<'expense' | 'income'>('expense')
  const [category, setCategory] = useState('Groceries')
  const [customCategories, setCustomCategories] = useState<string[]>(() => readStoredCategories(session?.hotel.id))
  const [hiddenCategories, setHiddenCategories] = useState<string[]>(() => readHiddenCategories(session?.hotel.id))
  const [addingCategory, setAddingCategory] = useState(false)
  const [newCategory, setNewCategory] = useState('')
  const [managingCategories, setManagingCategories] = useState(false)
  const [editingCategory, setEditingCategory] = useState<string | null>(null)
  const [categoryDraft, setCategoryDraft] = useState('')
  const [entryPage, setEntryPage] = useState(1)
  const [registerPage, setRegisterPage] = useState(1)
  const requestSeq = useRef(0)
  const mayManage = can(session, 'finance.manage')
  const currency = session?.hotel.currency_code ?? 'INR'
  const customRange = period === 'custom'
  const query = customRange ? (from && to ? `from=${from}&to=${to}` : '') : `period=${period}`
  const outletQuery = activeOutletId ? `${query}&outlet_id=${activeOutletId}` : query

  const load = useCallback(async () => {
    if (!query) return
    const seq = ++requestSeq.current
    setLoading(true)
    setError('')
    try {
      const [summary, dailyRegister] = await Promise.all([
        api.get<ApiEnvelope<FinanceSummary>>(`/api/v1/finance/summary?${outletQuery}`),
        api.get<ApiEnvelope<DailyMoneyRegisterOverview>>(`/api/v1/finance/daily-register?${outletQuery}`),
      ])
      if (seq !== requestSeq.current) return
      setData(summary.data.data)
      setFrom(summary.data.data.period.from)
      setTo(summary.data.data.period.to)
      setRegisterData(dailyRegister.data.data)
      setEntryPage(1)
      setRegisterPage(1)
    } catch (err) {
      if (seq !== requestSeq.current) return
      setError(errorMessage(err))
    } finally {
      if (seq === requestSeq.current) setLoading(false)
    }
  }, [outletQuery, query])
  useEffect(() => { const task = window.setTimeout(() => { void load() }, 0); return () => window.clearTimeout(task) }, [load])
  useEffect(() => {
    const task = window.setTimeout(() => {
      setCustomCategories(readStoredCategories(session?.hotel.id))
      setHiddenCategories(readHiddenCategories(session?.hotel.id))
    }, 0)
    return () => window.clearTimeout(task)
  }, [session?.hotel.id])
  function persistCategories(next: string[]) {
    setCustomCategories(next)
    const key = categoryStorageKey(session?.hotel.id)
    if (key) window.localStorage.setItem(key, JSON.stringify(next))
  }
  function persistHiddenCategories(next: string[]) {
    setHiddenCategories(next)
    const key = hiddenCategoryStorageKey(session?.hotel.id)
    if (key) window.localStorage.setItem(key, JSON.stringify(next))
  }
  function rememberCategory(name: string) {
    const trimmed = name.trim()
    if (!trimmed) return
    if (hiddenCategories.some((item) => item.toLowerCase() === trimmed.toLowerCase())) {
      persistHiddenCategories(hiddenCategories.filter((item) => item.toLowerCase() !== trimmed.toLowerCase()))
    }
    const already = uniqueCategories(CATEGORIES, customCategories).some((item) => item.toLowerCase() === trimmed.toLowerCase())
    if (already) return
    persistCategories(uniqueCategories(customCategories, [trimmed]))
  }
  function addCategoryChip() {
    const trimmed = newCategory.trim()
    if (!trimmed) return
    rememberCategory(trimmed)
    setCategory(trimmed)
    setNewCategory('')
    setAddingCategory(false)
  }
  function removeCategoryChip(name: string) {
    const key = name.toLowerCase()
    persistCategories(customCategories.filter((item) => item.toLowerCase() !== key))
    persistHiddenCategories(uniqueCategories(hiddenCategories, [name]))
    if (category.toLowerCase() === key) {
      const next = whatForOptions.find((item) => item.toLowerCase() !== key) ?? ''
      setCategory(next)
    }
    if (editingCategory?.toLowerCase() === key) setEditingCategory(null)
  }
  function startCategoryEdit(name: string) {
    setEditingCategory(name)
    setCategoryDraft(name)
  }
  function saveCategoryEdit() {
    if (!editingCategory) return
    const renamed = categoryDraft.trim()
    if (!renamed) return
    const oldKey = editingCategory.toLowerCase()
    const sameName = renamed.toLowerCase() === oldKey
    if (!sameName) {
      persistCategories(uniqueCategories(customCategories.filter((item) => item.toLowerCase() !== oldKey), [renamed]))
      persistHiddenCategories(uniqueCategories(hiddenCategories.filter((item) => item.toLowerCase() !== renamed.toLowerCase()), [editingCategory]))
      if (category.toLowerCase() === oldKey) setCategory(renamed)
    }
    setEditingCategory(null)
    setCategoryDraft('')
  }

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
    if (existing?.category) rememberCategory(existing.category)
    setAddingCategory(false)
    setNewCategory('')
    setManagingCategories(false)
    setEditingCategory(null)
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
      rememberCategory(payload.category)
      setEntryForm(null)
      setSuccess(entryType === 'income' ? 'Income saved.' : 'Expense saved.')
      await load()
    } catch (err) {
      setFormError(errorMessage(err))
    } finally {
      setSaving(false)
    }
  }

  async function saveDailyRegister(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const values = new FormData(event.currentTarget)
    const payload = {
      business_date: String(values.get('business_date')),
      actual_online: Number(values.get('actual_online')),
      actual_cash: Number(values.get('actual_cash')),
      notes: String(values.get('notes') || '').trim() || null,
    }
    setFormError('')
    setSaving(true)
    try {
      if (registerForm && registerForm !== 'new' && registerForm.register_id) await api.put(`/api/v1/finance/daily-register/${registerForm.register_id}`, payload)
      else await api.post('/api/v1/finance/daily-register', payload)
      setRegisterForm(null)
      setSuccess('Daily cash and online balance saved.')
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

  const editingEntry = entryForm && entryForm !== 'new' ? entryForm : null
  const editingRegister = registerForm && registerForm !== 'new' ? registerForm : null
  const focusCategory = linkedCategory.toLowerCase()
  const entries = (data?.entries ?? []).filter((entry) => isLedger(entry) && (!focusCategory || entry.category.toLowerCase() === focusCategory))
  const entryPages = Math.max(1, Math.ceil(entries.length / ENTRY_PAGE_SIZE))
  const currentEntryPage = Math.min(entryPage, entryPages)
  const pagedEntries = entries.slice((currentEntryPage - 1) * ENTRY_PAGE_SIZE, currentEntryPage * ENTRY_PAGE_SIZE)
  const entryFrom = entries.length ? (currentEntryPage - 1) * ENTRY_PAGE_SIZE + 1 : 0
  const entryTo = Math.min(currentEntryPage * ENTRY_PAGE_SIZE, entries.length)
  const hiddenCategoryKeys = new Set(hiddenCategories.map((item) => item.toLowerCase()))
  const whatForOptions = uniqueCategories(CATEGORIES, customCategories, entries.filter(isLedger).map((entry) => entry.category)).filter((item) => !hiddenCategoryKeys.has(item.toLowerCase()))
  const registerRows = registerData?.rows ?? []
  const registerPages = Math.max(1, Math.ceil(registerRows.length / REGISTER_PAGE_SIZE))
  const currentRegisterPage = Math.min(registerPage, registerPages)
  const pagedRegisterRows = registerRows.slice((currentRegisterPage - 1) * REGISTER_PAGE_SIZE, currentRegisterPage * REGISTER_PAGE_SIZE)
  const registerFrom = registerRows.length ? (currentRegisterPage - 1) * REGISTER_PAGE_SIZE + 1 : 0
  const registerTo = Math.min(currentRegisterPage * REGISTER_PAGE_SIZE, registerRows.length)
  const calculation = registerData?.calculation

  return <div className="money-page">
    <Heading eyebrow="MONEY" title="Earnings and daily balances" text="Track billed net sales, expenses, and each outlet's verified cash and online closing balance." action={<div className="heading-actions">{mayManage && <button className="button button-primary" type="button" onClick={() => openEntry('new')}><CirclePlus size={16} />Add expense</button>}</div>} />
    <section className="report-filter-bar">
      <label className="field"><span>Period</span><select value={period} onChange={(event) => selectPeriod(event.target.value)} aria-label="Period"><option value="today">Today</option><option value="week">This week</option><option value="month">This month</option><option value="year">This year</option><option value="custom">Custom dates</option></select></label>
      <label className="field"><span>From</span><input type="date" value={from} disabled={!customRange} readOnly={!customRange} onChange={(event) => { if (!customRange) return; setFrom(event.target.value) }} /></label>
      <label className="field"><span>To</span><input type="date" value={to} disabled={!customRange} readOnly={!customRange} onChange={(event) => { if (!customRange) return; setTo(event.target.value) }} /></label>
      <button className="button button-secondary" type="button" disabled={loading} onClick={() => void load()}><RefreshCw size={15} className={loading ? 'icon-spin' : ''} />{loading ? 'Refreshing…' : 'Refresh'}</button>
    </section>
    <Notice error={error} success={success} />
    {linkedCategory && <p className="reports-drilldown-note">Showing recorded entries for <strong>{linkedCategory}</strong>.</p>}
    {loading && (!data || !registerData) ? <section className="dashboard-metrics">{['one', 'two', 'three', 'four'].map((key) => <div key={key} className="loading-skeleton metric-skeleton" />)}</section> : data && registerData && <>
      <section className="dashboard-metrics money-register-kpis">
        <article className="dashboard-metric blue"><span><WalletCards size={18} /></span><small>Payments received</small><strong>{money(registerData.summary.collections, currency)}</strong><em>Cash and online collections, less refunds</em></article>
        <article className="dashboard-metric amber"><span><HandCoins size={18} /></span><small>Total Expenses</small><strong>{money(registerData.summary.expenses, currency)}</strong><em>Recorded expenses in this period</em></article>
        <article className="dashboard-metric blue"><span><CreditCard size={18} /></span><small>Total Online (Received)</small><strong>{money(registerData.summary.actual_online, currency)}</strong><em>Latest verified online balance</em></article>
        <article className="dashboard-metric green"><span><Banknote size={18} /></span><small>Total Cash (In Hand)</small><strong>{money(registerData.summary.actual_cash, currency)}</strong><em>Latest verified cash balance</em></article>
      </section>
      <section className="data-card daily-register-card">
        <div className="data-card-heading daily-register-heading"><BookOpenCheck size={20} /><div><h2>Daily Cash &amp; Online Register</h2><small>Track daily closing cash and online money, then verify it against the expected balance.</small></div>{mayManage && <button className="button button-primary" type="button" onClick={() => { const todayRow = registerRows.find((row) => row.business_date === today); setRegisterForm(todayRow ?? 'new'); setFormError(''); setSuccess('') }}><Plus size={16} />Add Daily Entry</button>}</div>
        <div className="data-table"><table className="report-table daily-register-table"><thead><tr><th>Date</th><th>Yesterday Total<br />(A)</th><th>Payments received<br />(B)</th><th>Expenses<br />(C)</th><th>Expected Total<br />(A + B − C)</th><th>Actual Online</th><th>Actual Cash</th><th>Actual Total<br />(D)</th><th>Difference<br />(D − Expected)</th><th>Status</th><th>Actions</th></tr></thead><tbody>
          {pagedRegisterRows.map((row) => <tr key={row.business_date} className={row.business_date === today ? 'register-today' : ''}>
            <td><strong>{registerDate(row.business_date, today)}</strong>{row.notes ? <small title={row.notes}>{row.notes}</small> : null}</td>
            <td>{money(row.yesterday_total, currency)}</td><td>{money(row.collections, currency)}</td><td>{money(row.expenses, currency)}</td>
            <td><strong>{money(row.expected_total, currency)}</strong>{Number(row.other_income) !== 0 && <small>Includes {money(row.other_income, currency)} other income</small>}</td>
            <td>{row.actual_online === null ? '—' : money(row.actual_online, currency)}</td><td>{row.actual_cash === null ? '—' : money(row.actual_cash, currency)}</td><td><strong>{row.actual_total === null ? '—' : money(row.actual_total, currency)}</strong></td>
            <td className={row.status === 'short' ? 'negative' : row.status === 'over' ? 'positive' : ''}>{row.difference === null ? '—' : money(row.difference, currency)}</td>
            <td>{row.status === 'matched' ? <span className="register-status matched"><CircleCheck size={13} />Matched</span> : row.status === 'short' ? <span className="register-status short"><CircleAlert size={13} />Missing {money(Math.abs(Number(row.difference)), currency)}</span> : row.status === 'over' ? <span className="register-status over"><CircleAlert size={13} />Extra {money(row.difference ?? 0, currency)}</span> : <span className="register-status pending">Not entered</span>}</td>
            <td>{mayManage && <button className="row-action register-edit" type="button" title={row.register_id ? `Edit ${registerDate(row.business_date, today)}` : `Add ${registerDate(row.business_date, today)}`} aria-label={row.register_id ? `Edit ${registerDate(row.business_date, today)}` : `Add ${registerDate(row.business_date, today)}`} onClick={() => { setRegisterForm(row); setFormError(''); setSuccess('') }}>{row.register_id ? <Pencil size={16} /> : <Plus size={16} />}</button>}</td>
          </tr>)}
          {!pagedRegisterRows.length && <tr><td colSpan={11} className="empty-row">No dates are available for this period.</td></tr>}
        </tbody></table></div>
        <nav className="money-pager daily-register-pager" aria-label="Daily register pages"><span>Showing {registerFrom}–{registerTo} of {registerRows.length} entries</span><div><button type="button" className="button button-secondary" disabled={currentRegisterPage <= 1} onClick={() => setRegisterPage(currentRegisterPage - 1)}><ChevronLeft size={16} />Prev</button><span>Page {currentRegisterPage} of {registerPages}</span><button type="button" className="button button-secondary" disabled={currentRegisterPage >= registerPages} onClick={() => setRegisterPage(currentRegisterPage + 1)}>Next<ChevronRight size={16} /></button></div></nav>
      </section>
      {calculation && <section className="data-card daily-calculation-card"><div className="data-card-heading"><Calculator size={19} /><h2>{calculation.business_date === today ? "Today's" : 'Daily'} Calculation ({registerDate(calculation.business_date, today).replace(' (Today)', '')})</h2></div><div className="daily-calculation-flow"><span><small>Yesterday Total</small><strong>{money(calculation.yesterday_total, currency)}</strong></span><b>+</b><span className="positive-box"><small>Payments received</small><strong>{money(calculation.collections, currency)}</strong></span>{Number(calculation.other_income) !== 0 && <><b>+</b><span className="positive-box"><small>Other Income</small><strong>{money(calculation.other_income, currency)}</strong></span></>}<b>−</b><span className="negative-box"><small>Expenses</small><strong>{money(calculation.expenses, currency)}</strong></span><b>=</b><span className="expected-box"><small>Expected Total</small><strong>{money(calculation.expected_total, currency)}</strong></span><i /><span className="positive-box"><small>Actual Total</small><strong>{calculation.actual_total === null ? 'Not entered' : money(calculation.actual_total, currency)}</strong></span><span className={calculation.status === 'short' ? 'negative-box' : 'positive-box'}><small>Difference</small><strong>{calculation.difference === null ? '—' : money(calculation.difference, currency)}</strong></span><span className={`calculation-result ${calculation.status}`}>{calculation.status === 'matched' ? <><CircleCheck size={16} />Amounts Matched</> : calculation.status === 'not_entered' ? 'Awaiting daily entry' : calculation.status === 'short' ? 'Cash is short' : 'Extra balance found'}</span></div></section>}
      <section className="data-card money-entries">
        <div className="data-card-heading"><HandCoins size={19} /><h2>Entries</h2><span>{entries.length ? `${entryFrom}–${entryTo} of ${entries.length}` : 0}</span></div>
        <div className="data-list">{pagedEntries.map((entry) => <div className={`data-row money-entry-row${focusCategory && entry.category.toLowerCase() === focusCategory ? ' is-match' : ''}`} key={String(entry.id)}><div><strong>{entry.category}{firstName(entry.creator?.name) && <span className="money-entry-creator"> (by {firstName(entry.creator?.name)})</span>}</strong><small>{payOn(entry.occurred_on)} · {entryKind(entry)}{entry.comment ? ` · ${entry.comment}` : ''}</small></div><b className={entry.type === 'income' ? 'positive' : 'negative'}>{entry.type === 'income' ? '+' : '−'}{money(entry.amount, currency)}</b>{mayManage && isLedger(entry) && <span className="row-actions"><button className="row-action" type="button" title="Edit entry" onClick={() => openEntry(entry)}><Pencil size={18} /></button><button className="row-action danger" type="button" title="Delete entry" onClick={() => setDeleting(entry)}><Trash2 size={18} /></button></span>}</div>)}{!entries.length && <p className="empty-row">No deducted or added entries in this period.</p>}</div>
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
        <div className="field"><span className="money-category-heading">What for<button type="button" onClick={() => { setManagingCategories((current) => !current); setEditingCategory(null) }}>{managingCategories ? 'Done' : 'Manage'}</button></span>
          <div className="money-chips" role="group" aria-label="What for">
            {whatForOptions.map((item) => editingCategory?.toLowerCase() === item.toLowerCase()
              ? <span className="money-chip-edit" key={item}>
                  <input value={categoryDraft} onChange={(event) => setCategoryDraft(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); saveCategoryEdit() } }} maxLength={80} aria-label={`Rename ${item}`} autoFocus />
                  <button type="button" className="money-chip-icon save" title="Save category name" aria-label={`Save renamed ${item}`} disabled={!categoryDraft.trim()} onClick={saveCategoryEdit}><Check size={14} /></button>
                  <button type="button" className="money-chip-icon" title="Cancel editing" aria-label={`Cancel editing ${item}`} onClick={() => setEditingCategory(null)}><X size={14} /></button>
                </span>
              : <span className={`money-chip${category.toLowerCase() === item.toLowerCase() ? ' selected' : ''}`} key={item}>
                  <button type="button" className="money-chip-select" onClick={() => setCategory(item)}>{item}</button>
                  {managingCategories && <span className="money-chip-controls">
                    <button type="button" className="money-chip-icon" title={`Rename ${item}`} aria-label={`Rename ${item}`} onClick={() => startCategoryEdit(item)}><Pencil size={13} /></button>
                    <button type="button" className="money-chip-icon danger" title={`Remove ${item}`} aria-label={`Remove ${item}`} onClick={() => removeCategoryChip(item)}><Trash2 size={13} /></button>
                  </span>}
                </span>)}
            {addingCategory
              ? <span className="money-chip-add">
                  <input value={newCategory} onChange={(event) => setNewCategory(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); addCategoryChip() } }} placeholder="New category" maxLength={80} autoComplete="off" autoFocus />
                  <button type="button" className="button button-primary" disabled={!newCategory.trim()} onClick={addCategoryChip}>Add</button>
                  <button type="button" className="button button-secondary" onClick={() => { setAddingCategory(false); setNewCategory('') }}>Cancel</button>
                </span>
              : <button type="button" className="money-chip-new" onClick={() => setAddingCategory(true)}><Plus size={14} />Add</button>}
          </div>
          <input name="category" value={category} onChange={(event) => setCategory(event.target.value)} required placeholder="Or type a category" autoComplete="off" />
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
    <Modal open={registerForm !== null} title={editingRegister?.register_id ? 'Edit daily balance' : 'Add daily balance'} description="Enter the verified closing balances. Sales, refunds, expenses, and the expected total are calculated automatically." onClose={() => !saving && setRegisterForm(null)}>
      <form key={`${editingRegister?.business_date ?? 'new'}-${editingRegister?.register_id ?? 0}`} className="compact-form money-form daily-register-form" onSubmit={saveDailyRegister}>
        <label className="field"><span>Business date</span><input name="business_date" type="date" max={today} required defaultValue={editingRegister?.business_date ?? today} /></label>
        <div className="form-grid">
          <label className="field money-amount-field"><span>Actual online closing balance</span><input name="actual_online" type="number" inputMode="decimal" min="0" max="999999999999.99" step="0.01" required defaultValue={editingRegister?.actual_online ?? ''} placeholder="0.00" autoComplete="off" /></label>
          <label className="field money-amount-field"><span>Actual cash in hand</span><input name="actual_cash" type="number" inputMode="decimal" min="0" max="999999999999.99" step="0.01" required defaultValue={editingRegister?.actual_cash ?? ''} placeholder="0.00" autoComplete="off" /></label>
        </div>
        <label className="field"><span>Note (optional)</span><textarea name="notes" rows={3} maxLength={1000} defaultValue={editingRegister?.notes ?? ''} placeholder="Reason for any shortage, excess, or correction" /></label>
        {formError && <p className="form-error form-notice">{formError}</p>}
        <div className="form-action-row"><button className="button button-primary button-touch" type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save daily entry'}</button><button className="button button-secondary button-touch" type="button" disabled={saving} onClick={() => setRegisterForm(null)}>Close</button></div>
      </form>
    </Modal>
    <ConfirmDialog open={Boolean(deleting)} title="Delete this entry?" text="This removes the recorded expense or other-income entry." confirmLabel="Delete entry" onClose={() => setDeleting(null)} onConfirm={() => void deleteEntry()} />
  </div>
}
