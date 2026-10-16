import { ArrowDownUp, Banknote, BarChart3, CreditCard, Download, HandCoins, Printer, RefreshCw, UtensilsCrossed, Wallet, WalletCards } from 'lucide-react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Bar, BarChart, CartesianGrid, Cell, ComposedChart, Legend, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { useAuth } from '../features/auth/AuthContext'
import { can } from '../features/auth/permissions'
import { api, errorMessage } from '../lib/api'
import type { ApiEnvelope, Outlet, Paginated } from '../types/api'

const PERIODS = [
  { id: 'today', label: 'Today' },
  { id: 'yesterday', label: 'Yesterday' },
  { id: 'last_7', label: 'Last 7 Days' },
  { id: 'month', label: 'This Month' },
  { id: 'last_month', label: 'Last Month' },
] as const

type PeriodId = typeof PERIODS[number]['id'] | 'custom'
type Kpi = { amount: string; previous: string; change_percent: number | null; bills?: number; percent?: number | null }
type Overview = {
  period: { from: string; to: string }
  previous_period: { from: string; to: string }
  outlet_id: number | null
  expenses_hotel_wide: boolean
  kpis: { gross_sales: Kpi; net_sales: Kpi; expenses: Kpi; surplus: Kpi; discounts: Kpi }
  series: { label: string; net_sales: string; expenses: string }[]
  expense_breakdown: { category: string; amount: string; count: number; share: number }[]
  menu_performance: {
    categories: { category: string; quantity: number; net_sales: string; share: number }[]
    items: { item: string; category: string; quantity: number; net_sales: string }[]
  }
  payments: {
    collected: string
    outstanding: string
    methods: { method: string; amount: string; count: number; share: number }[]
  }
  daily: { label: string; key: string; bills: number; gross_sales: string; discounts: string; net_sales: string; expenses: string; surplus: string }[]
}
type ReportExport = { id: number; type: string; format: string; status: string }

const money = (value: string | number | undefined, currency = 'INR') => new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 2 }).format(Number(value ?? 0))

function Heading({ eyebrow, title, text, action }: { eyebrow: string; title: string; text: string; action?: ReactNode }) {
  return <section className="page-heading"><div><p className="eyebrow">{eyebrow}</p><h1>{title}</h1><p>{text}</p></div>{action}</section>
}
function Notice({ error, success }: { error?: string; success?: string }) {
  return <>{error && <p className="form-error form-notice">{error}</p>}{success && <p className="form-success form-notice">{success}</p>}</>
}
function changeLabel(percent: number | null) {
  if (percent === null) return null
  const sign = percent > 0 ? '+' : ''
  return `${sign}${percent}% vs previous period`
}

export function ReportsPage() {
  const { session } = useAuth()
  const currency = session?.hotel.currency_code ?? 'INR'
  const [period, setPeriod] = useState<PeriodId>('month')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [outlet, setOutlet] = useState('')
  const [outlets, setOutlets] = useState<Outlet[]>([])
  const [data, setData] = useState<Overview | null>(null)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [loading, setLoading] = useState(true)
  const [exporting, setExporting] = useState(false)
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc')
  const [itemSort, setItemSort] = useState<'sales' | 'quantity'>('sales')
  const mayExport = can(session, 'reports.export')
  const mayFinance = can(session, 'finance.view')

  const query = useMemo(() => {
    const params = new URLSearchParams()
    if (period === 'custom') {
      if (!from || !to) return ''
      params.set('from', from)
      params.set('to', to)
    } else {
      params.set('period', period)
    }
    if (outlet) params.set('outlet_id', outlet)
    return params.toString()
  }, [from, outlet, period, to])

  const load = useCallback(async () => {
    if (!query) return
    setLoading(true)
    setError('')
    try {
      const response = await api.get<ApiEnvelope<Overview>>(`/api/v1/reports/overview?${query}`)
      setData(response.data.data)
      setFrom(response.data.data.period.from)
      setTo(response.data.data.period.to)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setLoading(false)
    }
  }, [query])

  useEffect(() => {
    const task = window.setTimeout(() => { void load() }, 0)
    return () => window.clearTimeout(task)
  }, [load])
  useEffect(() => {
    const task = window.setTimeout(() => {
      void api.get<ApiEnvelope<Outlet[]>>('/api/v1/outlets').then((response) => setOutlets(response.data.data)).catch(() => undefined)
    }, 0)
    return () => window.clearTimeout(task)
  }, [])

  function selectPeriod(next: PeriodId) {
    setPeriod(next)
    setSuccess('')
    if (next === 'custom') return
    setFrom('')
    setTo('')
  }

  async function downloadExport(id: number) {
    const response = await api.get(`/api/v1/reports/exports/${id}/download`, { responseType: 'blob' })
    const url = URL.createObjectURL(response.data as Blob)
    const link = document.createElement('a')
    link.href = url
    link.download = 'business-overview.csv'
    link.click()
    URL.revokeObjectURL(url)
  }

  async function exportCsv() {
    if (!mayExport) return
    setError('')
    setSuccess('')
    setExporting(true)
    try {
      await api.post('/api/v1/reports/exports', {
        type: 'business_overview',
        format: 'csv',
        filters: { period: period === 'custom' ? undefined : period, from: from || undefined, to: to || undefined, outlet_id: outlet || undefined },
      })
      for (let attempt = 0; attempt < 20; attempt++) {
        const list = await api.get<Paginated<ReportExport>>('/api/v1/reports/exports?per_page=5')
        const item = list.data.data.find((entry) => entry.type === 'business_overview')
        if (item?.status === 'completed') {
          await downloadExport(item.id)
          setSuccess('CSV downloaded.')
          return
        }
        if (item?.status === 'failed') {
          setError('Export failed. Please try again.')
          return
        }
        await new Promise((resolve) => window.setTimeout(resolve, 400))
      }
      setError('Export is still preparing. Try again in a moment.')
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setExporting(false)
    }
  }

  const chartSeries = (data?.series ?? []).map((row) => ({ label: row.label, net_sales: Number(row.net_sales), expenses: Number(row.expenses) }))
  const breakdownChart = (data?.expense_breakdown ?? []).map((row) => ({ category: row.category, amount: Number(row.amount) }))
  const menuChart = (data?.menu_performance?.categories ?? []).map((row) => ({ category: row.category, amount: Number(row.net_sales) }))
  const paymentChart = (data?.payments?.methods ?? []).map((row) => ({ name: methodLabel(row.method), amount: Number(row.amount) }))
  const paymentColors = ['#117548', '#2379ec', '#e79707', '#8b5cf6']
  const dailyRows = [...(data?.daily ?? [])].sort((left, right) => sortDir === 'asc' ? left.key.localeCompare(right.key) : right.key.localeCompare(left.key))
  const itemRows = [...(data?.menu_performance?.items ?? [])].sort((left, right) => itemSort === 'quantity' ? Number(right.quantity) - Number(left.quantity) : Number(right.net_sales) - Number(left.net_sales))
  const empty = Boolean(data) && !data?.series.length
  const moneyLink = (category: string) => `/app/money?from=${encodeURIComponent(data?.period.from ?? from)}&to=${encodeURIComponent(data?.period.to ?? to)}&category=${encodeURIComponent(category)}`

  return <div className="reports-page">
    <Heading eyebrow="REPORTS" title="Business Reports" text="Issued bills and recorded Money expenses for the selected hotel business dates." action={<div className="heading-actions"><button className="button button-secondary" type="button" onClick={() => window.print()}><Printer size={16} />Print</button>{mayExport && <button className="button button-primary" type="button" disabled={exporting} onClick={() => void exportCsv()}><Download size={16} />{exporting ? 'Exporting…' : 'Export CSV'}</button>}</div>} />
    <section className="report-filter-bar">
      <div className="money-chips reports-period-chips" role="group" aria-label="Period">
        {PERIODS.map((item) => <button type="button" key={item.id} className={period === item.id ? 'selected' : ''} onClick={() => selectPeriod(item.id)}>{item.label}</button>)}
      </div>
      <label className="field"><span>From</span><input type="date" value={from} onChange={(event) => { setPeriod('custom'); setFrom(event.target.value) }} /></label>
      <label className="field"><span>To</span><input type="date" value={to} onChange={(event) => { setPeriod('custom'); setTo(event.target.value) }} /></label>
      {outlets.length > 1 && <label className="field"><span>Outlet</span><select value={outlet} onChange={(event) => setOutlet(event.target.value)}><option value="">All outlets</option>{outlets.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>}
      <button className="button button-secondary" type="button" disabled={loading} onClick={() => void load()}><RefreshCw size={15} />{loading ? 'Refreshing…' : 'Refresh'}</button>
    </section>
    <Notice error={error} success={success} />
    {loading && !data ? <section className="dashboard-metrics reports-kpis">{['one', 'two', 'three', 'four', 'five'].map((key) => <div key={key} className="loading-skeleton metric-skeleton" />)}</section> : data && <>
      <section className="dashboard-metrics reports-kpis">
        <KpiCard tone="green" icon={<WalletCards size={18} />} label="Gross sales" value={money(data.kpis.gross_sales.amount, currency)} hint={`${data.kpis.gross_sales.bills ?? 0} bill${(data.kpis.gross_sales.bills ?? 0) === 1 ? '' : 's'}`} change={changeLabel(data.kpis.gross_sales.change_percent)} />
        <KpiCard tone="blue" icon={<BarChart3 size={18} />} label="Net sales" value={money(data.kpis.net_sales.amount, currency)} hint="Billed total after discounts" change={changeLabel(data.kpis.net_sales.change_percent)} />
        <KpiCard tone="amber" icon={<HandCoins size={18} />} label="Recorded expenses" value={money(data.kpis.expenses.amount, currency)} hint={data.expenses_hotel_wide ? 'Expenses are hotel-wide.' : 'Ledger plus standing costs'} change={changeLabel(data.kpis.expenses.change_percent)} />
        <KpiCard tone={Number(data.kpis.surplus.amount) < 0 ? 'red' : 'green'} icon={<Wallet size={18} />} label="Est. operating surplus" value={money(data.kpis.surplus.amount, currency)} hint={data.expenses_hotel_wide ? 'Expenses are hotel-wide. Before untracked costs.' : 'Before inventory and unrecorded costs'} change={changeLabel(data.kpis.surplus.change_percent)} />
        <KpiCard tone="purple" icon={<Banknote size={18} />} label="Discounts" value={money(data.kpis.discounts.amount, currency)} hint={`${data.kpis.discounts.bills ?? 0} discounted bill${(data.kpis.discounts.bills ?? 0) === 1 ? '' : 's'}${data.kpis.discounts.percent != null ? ` · ${data.kpis.discounts.percent}% of gross` : ''}`} change={changeLabel(data.kpis.discounts.change_percent)} />
      </section>
      <section className="dashboard-grid reports-charts">
        <article className="dashboard-card chart-card">
          <header><div><h2>Sales vs expenses</h2><p>Net sales and recorded expenses by day</p></div></header>
          {chartSeries.length ? <ResponsiveContainer width="100%" height={280}><ComposedChart data={chartSeries}><CartesianGrid strokeDasharray="3 3" stroke="#edf1f5" /><XAxis dataKey="label" fontSize={10} /><YAxis fontSize={10} /><Tooltip formatter={(value) => money(Number(value), currency)} /><Legend /><Bar dataKey="net_sales" name="Net sales" fill="#117548" radius={[5, 5, 0, 0]} /><Bar dataKey="expenses" name="Expenses" fill="#e79707" radius={[5, 5, 0, 0]} /></ComposedChart></ResponsiveContainer> : <p className="empty-row">No sales or expenses in this period.</p>}
        </article>
        <article className="dashboard-card chart-card">
          <header><div><h2>Expense breakdown</h2><p>Recorded spend by category</p></div></header>
          {breakdownChart.length ? <ResponsiveContainer width="100%" height={280}><BarChart data={breakdownChart} layout="vertical" margin={{ left: 8, right: 12 }}><CartesianGrid strokeDasharray="3 3" stroke="#edf1f5" /><XAxis type="number" fontSize={10} /><YAxis type="category" dataKey="category" width={92} fontSize={10} /><Tooltip formatter={(value) => money(Number(value), currency)} /><Bar dataKey="amount" name="Amount" fill="#2379ec" radius={[0, 5, 5, 0]} /></BarChart></ResponsiveContainer> : <p className="empty-row">No recorded expenses in this period.</p>}
        </article>
      </section>
      <section className="data-card reports-breakdown">
        <div className="data-card-heading"><HandCoins size={19} /><h2>Expense categories</h2><span>{data.expense_breakdown.length}</span></div>
        <div className="data-table"><table className="report-table"><thead><tr><th>Category</th><th>Transactions</th><th>Share</th><th>Amount</th></tr></thead><tbody>
          {data.expense_breakdown.map((row) => <tr key={row.category}><td>{mayFinance ? <Link to={moneyLink(row.category)}>{row.category}</Link> : row.category}</td><td>{row.count}</td><td>{row.share}%</td><td>{money(row.amount, currency)}</td></tr>)}
          {!data.expense_breakdown.length && <tr><td colSpan={4} className="empty-row">No expense categories to show.</td></tr>}
        </tbody></table></div>
      </section>
      <section className="data-card">
        <div className="data-card-heading"><BarChart3 size={19} /><h2>Daily business summary</h2><button type="button" className="button button-secondary" onClick={() => setSortDir((value) => value === 'asc' ? 'desc' : 'asc')}><ArrowDownUp size={14} />Date {sortDir === 'asc' ? '↑' : '↓'}</button></div>
        <div className="data-table"><table className="report-table"><thead><tr><th>Period</th><th>Bills</th><th>Gross</th><th>Discounts</th><th>Net</th><th>Expenses</th><th>Surplus</th></tr></thead><tbody>
          {dailyRows.map((row) => <tr key={row.key}><td>{row.label}</td><td>{row.bills}</td><td>{money(row.gross_sales, currency)}</td><td>{money(row.discounts, currency)}</td><td>{money(row.net_sales, currency)}</td><td>{money(row.expenses, currency)}</td><td className={Number(row.surplus) < 0 ? 'negative' : ''}>{money(row.surplus, currency)}</td></tr>)}
          {empty && <tr><td colSpan={7} className="empty-row">No activity in this period.</td></tr>}
        </tbody></table></div>
      </section>
      <section className="dashboard-grid reports-charts">
        <article className="dashboard-card chart-card">
          <header><div><h2>Menu performance</h2><p>Net line sales by menu category</p></div></header>
          {menuChart.length ? <ResponsiveContainer width="100%" height={280}><BarChart data={menuChart} layout="vertical" margin={{ left: 8, right: 12 }}><CartesianGrid strokeDasharray="3 3" stroke="#edf1f5" /><XAxis type="number" fontSize={10} /><YAxis type="category" dataKey="category" width={92} fontSize={10} /><Tooltip formatter={(value) => money(Number(value), currency)} /><Bar dataKey="amount" name="Net sales" fill="#117548" radius={[0, 5, 5, 0]} /></BarChart></ResponsiveContainer> : <p className="empty-row">No billed menu items in this period.</p>}
        </article>
        <article className="dashboard-card chart-card">
          <header><div><h2>Payment collections</h2><p>Successful payments on issued bills{Number(data.payments.outstanding) > 0 ? ` · ${money(data.payments.outstanding, currency)} outstanding` : ''}</p></div></header>
          {paymentChart.length ? <ResponsiveContainer width="100%" height={280}><PieChart><Pie data={paymentChart} dataKey="amount" nameKey="name" innerRadius={55} outerRadius={85}>{paymentChart.map((item, index) => <Cell key={item.name} fill={paymentColors[index % paymentColors.length]} />)}</Pie><Tooltip formatter={(value) => money(Number(value), currency)} /><Legend /></PieChart></ResponsiveContainer> : <p className="empty-row">No collections recorded for these bills.</p>}
        </article>
      </section>
      <section className="report-two-column reports-breakdown">
        <article className="data-card">
          <div className="data-card-heading"><UtensilsCrossed size={19} /><h2>Sales by category</h2><span>{data.menu_performance.categories.length}</span></div>
          <div className="data-table"><table className="report-table"><thead><tr><th>Category</th><th>Qty sold</th><th>Share</th><th>Net sales</th></tr></thead><tbody>
            {data.menu_performance.categories.map((row) => <tr key={row.category}><td>{row.category}</td><td>{formatQty(row.quantity)}</td><td>{row.share}%</td><td>{money(row.net_sales, currency)}</td></tr>)}
            {!data.menu_performance.categories.length && <tr><td colSpan={4} className="empty-row">No category sales to show.</td></tr>}
          </tbody></table></div>
        </article>
        <article className="data-card">
          <div className="data-card-heading"><CreditCard size={19} /><h2>Payments by method</h2><span>{money(data.payments.collected, currency)}</span></div>
          <div className="data-table"><table className="report-table"><thead><tr><th>Method</th><th>Transactions</th><th>Share</th><th>Collected</th></tr></thead><tbody>
            {data.payments.methods.map((row) => <tr key={row.method}><td>{methodLabel(row.method)}</td><td>{row.count}</td><td>{row.share}%</td><td>{money(row.amount, currency)}</td></tr>)}
            {!data.payments.methods.length && <tr><td colSpan={4} className="empty-row">No successful collections in this period.</td></tr>}
            <tr><td colSpan={3}>Outstanding billed balance</td><td className={Number(data.payments.outstanding) > 0 ? 'negative' : ''}>{money(data.payments.outstanding, currency)}</td></tr>
          </tbody></table></div>
        </article>
      </section>
      <section className="data-card">
        <div className="data-card-heading"><UtensilsCrossed size={19} /><h2>Top-selling items</h2>
          <div className="quick-export"><button type="button" className={itemSort === 'sales' ? 'selected' : ''} onClick={() => setItemSort('sales')}>By sales</button><button type="button" className={itemSort === 'quantity' ? 'selected' : ''} onClick={() => setItemSort('quantity')}>By quantity</button></div>
        </div>
        <div className="data-table"><table className="report-table"><thead><tr><th>Item</th><th>Category</th><th>Qty sold</th><th>Net sales</th></tr></thead><tbody>
          {itemRows.map((row) => <tr key={`${row.item}-${row.category}`}><td>{row.item}</td><td>{row.category}</td><td>{formatQty(row.quantity)}</td><td>{money(row.net_sales, currency)}</td></tr>)}
          {!itemRows.length && <tr><td colSpan={4} className="empty-row">No billed items in this period.</td></tr>}
        </tbody></table></div>
      </section>
    </>}
  </div>
}

function KpiCard({ icon, label, value, hint, change, tone }: { icon: ReactNode; label: string; value: string; hint?: string; change: string | null; tone: string }) {
  return <article className={`dashboard-metric ${tone}`}><span>{icon}</span><small>{label}</small><strong>{value}</strong><div className="reports-kpi-meta">{hint && <em className="reports-kpi-hint">{hint}</em>}{change && <em className="reports-kpi-change">{change}</em>}</div></article>
}
function methodLabel(method: string) {
  if (method === 'upi') return 'UPI'
  return method ? method.charAt(0).toUpperCase() + method.slice(1) : method
}
function formatQty(value: number) {
  return Number.isInteger(value) ? String(value) : String(Number(value.toFixed(3)))
}
