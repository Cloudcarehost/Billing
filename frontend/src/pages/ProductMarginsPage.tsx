import { AlertTriangle, ArrowDownUp, BarChart3, Boxes, CircleDollarSign, Printer, RefreshCw, Search, TrendingUp } from 'lucide-react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { Bar, BarChart, CartesianGrid, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { useAuth } from '../features/auth/AuthContext'
import { api, errorMessage } from '../lib/api'
import type { ApiEnvelope, Outlet } from '../types/api'

const PERIODS = [
  { id: 'today', label: 'Today' },
  { id: 'yesterday', label: 'Yesterday' },
  { id: 'last_7', label: 'Last 7 Days' },
  { id: 'month', label: 'This Month' },
  { id: 'last_month', label: 'Last Month' },
] as const

type PeriodId = typeof PERIODS[number]['id'] | 'custom'
type MarginItem = {
  product_id: number | null
  item: string
  category: string
  quantity: number
  average_selling_price: string
  average_cost_price: string
  unit_margin: string
  gross_sales: string
  discounts: string
  net_sales: string
  total_cost: string
  profit: string
  margin_percent: number | null
  missing_cost: boolean
  uses_current_cost: boolean
}
type MarginReport = {
  period: { from: string; to: string }
  outlet_id: number | null
  categories: string[]
  summary: {
    quantity: number
    gross_sales: string
    discounts: string
    net_sales: string
    total_cost: string
    profit: string
    margin_percent: number | null
    missing_cost_items: number
    current_cost_items: number
    low_margin_items: number
  }
  items: MarginItem[]
}
type SortKey = 'profit' | 'margin_percent' | 'unit_margin' | 'quantity' | 'net_sales'

const money = (amount: string | number | undefined, currency: string) => new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 2 }).format(Number(amount ?? 0))
const qty = (quantity: number) => Number.isInteger(quantity) ? quantity.toString() : quantity.toFixed(3).replace(/\.?0+$/, '')

export function ProductMarginsPage() {
  const { session } = useAuth()
  const currency = session?.hotel.currency_code ?? 'INR'
  const [period, setPeriod] = useState<PeriodId>('month')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [outlet, setOutlet] = useState('')
  const [category, setCategory] = useState('')
  const [search, setSearch] = useState('')
  const [appliedSearch, setAppliedSearch] = useState('')
  const [sortBy, setSortBy] = useState<SortKey>('profit')
  const [sortDescending, setSortDescending] = useState(true)
  const [outlets, setOutlets] = useState<Outlet[]>([])
  const [data, setData] = useState<MarginReport | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

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
    if (category) params.set('category', category)
    if (appliedSearch) params.set('search', appliedSearch)
    return params.toString()
  }, [appliedSearch, category, from, outlet, period, to])

  const load = useCallback(async () => {
    if (!query) return
    setLoading(true)
    setError('')
    try {
      const response = await api.get<ApiEnvelope<MarginReport>>(`/api/v1/reports/product-margins?${query}`)
      setData(response.data.data)
      setFrom(response.data.data.period.from)
      setTo(response.data.data.period.to)
    } catch (requestError) {
      setError(errorMessage(requestError))
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

  const rows = useMemo(() => [...(data?.items ?? [])].sort((left, right) => {
    const leftValue = Number(left[sortBy] ?? 0)
    const rightValue = Number(right[sortBy] ?? 0)
    return sortDescending ? rightValue - leftValue : leftValue - rightValue
  }), [data?.items, sortBy, sortDescending])
  const chartRows = useMemo(() => [...(data?.items ?? [])]
    .sort((left, right) => Number(right.profit) - Number(left.profit))
    .slice(0, 10)
    .map((item) => ({ item: item.item, profit: Number(item.profit) })), [data?.items])

  function selectPeriod(next: PeriodId) {
    setPeriod(next)
    if (next !== 'custom') {
      setFrom('')
      setTo('')
    }
  }

  return <div className="reports-page margin-report-page">
    <section className="page-heading"><div><p className="eyebrow">REPORTS · PRODUCT MARGIN</p><h1>Product Margin</h1><p>Compare invoiced selling prices with their recorded cost snapshots to understand item-level gross profit.</p></div><div className="heading-actions"><button className="button button-secondary" type="button" onClick={() => window.print()}><Printer size={16} />Print</button></div></section>
    <section className="report-filter-bar margin-filter-bar">
      <div className="money-chips reports-period-chips" role="group" aria-label="Period">
        {PERIODS.map((item) => <button type="button" key={item.id} className={period === item.id ? 'selected' : ''} onClick={() => selectPeriod(item.id)}>{item.label}</button>)}
      </div>
      <label className="field"><span>From</span><input type="date" value={from} onChange={(event) => { setPeriod('custom'); setFrom(event.target.value) }} /></label>
      <label className="field"><span>To</span><input type="date" value={to} onChange={(event) => { setPeriod('custom'); setTo(event.target.value) }} /></label>
      {outlets.length > 1 && <label className="field"><span>Outlet</span><select value={outlet} onChange={(event) => setOutlet(event.target.value)}><option value="">All outlets</option>{outlets.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>}
      <label className="field"><span>Category</span><select value={category} onChange={(event) => setCategory(event.target.value)}><option value="">All categories</option>{data?.categories.map((item) => <option key={item} value={item}>{item}</option>)}</select></label>
      <form className="margin-search" onSubmit={(event) => { event.preventDefault(); setAppliedSearch(search.trim()) }}><label className="field"><span>Item</span><span className="margin-search-input"><Search size={15} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search item" /></span></label><button className="button button-secondary" type="submit">Search</button></form>
      <button className="button button-secondary" type="button" disabled={loading} onClick={() => void load()}><RefreshCw size={15} className={loading ? 'spin' : ''} />{loading ? 'Refreshing…' : 'Refresh'}</button>
    </section>
    {error && <p className="form-error form-notice">{error}</p>}
    {data?.summary.missing_cost_items ? <aside className="margin-warning"><AlertTriangle size={18} /><div><strong>{data.summary.missing_cost_items} item{data.summary.missing_cost_items === 1 ? '' : 's'} have zero recorded cost</strong><span>Their displayed profit may be overstated. Add accurate cost prices to improve future reports; past invoices retain their original snapshots.</span></div></aside> : null}
    {loading && !data ? <section className="dashboard-metrics margin-kpis">{['one', 'two', 'three', 'four', 'five'].map((key) => <div key={key} className="loading-skeleton metric-skeleton" />)}</section> : data && <>
      <section className="dashboard-metrics margin-kpis">
        <MarginKpi tone="blue" icon={<CircleDollarSign size={18} />} label="Net item sales" value={money(data.summary.net_sales, currency)} hint={Number(data.summary.discounts) > 0 ? `${money(data.summary.discounts, currency)} discounts allocated` : 'Before tax'} />
        <MarginKpi tone="amber" icon={<Boxes size={18} />} label="Recorded item cost" value={money(data.summary.total_cost, currency)} hint="Cost snapshot × quantity" />
        <MarginKpi tone={Number(data.summary.profit) < 0 ? 'red' : 'green'} icon={<TrendingUp size={18} />} label="Gross profit" value={money(data.summary.profit, currency)} hint="Net item sales − recorded cost" />
        <MarginKpi tone="purple" icon={<BarChart3 size={18} />} label="Gross margin" value={data.summary.margin_percent == null ? '—' : `${data.summary.margin_percent}%`} hint={`${data.summary.low_margin_items} non-profitable item${data.summary.low_margin_items === 1 ? '' : 's'}`} />
        <MarginKpi tone="green" icon={<Boxes size={18} />} label="Units sold" value={qty(data.summary.quantity)} hint={`${data.items.length} item${data.items.length === 1 ? '' : 's'} in this view`} />
      </section>
      <section className="dashboard-card chart-card margin-chart-card">
        <header><div><h2>Highest total profit</h2><p>Top items after allocated bill discounts and recorded item costs</p></div></header>
        {chartRows.length ? <ResponsiveContainer width="100%" height={300}><BarChart data={chartRows} layout="vertical" margin={{ left: 16, right: 24 }}><CartesianGrid strokeDasharray="3 3" stroke="#edf1f5" /><XAxis type="number" fontSize={10} tickFormatter={(value) => Number(value).toLocaleString('en-IN')} /><YAxis type="category" dataKey="item" width={110} fontSize={10} /><Tooltip formatter={(value) => money(Number(value), currency)} /><Bar dataKey="profit" name="Profit" radius={[0, 5, 5, 0]}>{chartRows.map((item) => <Cell key={item.item} fill={item.profit < 0 ? '#c73737' : '#117548'} />)}</Bar></BarChart></ResponsiveContainer> : <p className="empty-row">No issued item sales in this period.</p>}
      </section>
      <section className="data-card margin-table-card">
        <div className="data-card-heading"><TrendingUp size={19} /><div><h2>Item profitability</h2><small>Tax excluded · discounts allocated proportionally</small></div><div className="margin-sort"><label><span>Sort by</span><select value={sortBy} onChange={(event) => setSortBy(event.target.value as SortKey)}><option value="profit">Total profit</option><option value="margin_percent">Margin %</option><option value="unit_margin">Unit margin</option><option value="quantity">Quantity sold</option><option value="net_sales">Net sales</option></select></label><button type="button" className="button button-secondary" title={sortDescending ? 'Sort ascending' : 'Sort descending'} aria-label={sortDescending ? 'Sort ascending' : 'Sort descending'} onClick={() => setSortDescending((current) => !current)}><ArrowDownUp size={14} />{sortDescending ? 'High to low' : 'Low to high'}</button></div></div>
        <div className="data-table"><table className="report-table margin-report-table"><thead><tr><th>Item</th><th>Category</th><th>Avg selling</th><th>Avg cost</th><th>Unit margin</th><th>Qty sold</th><th>Net sales</th><th>Total cost</th><th>Profit</th><th>Margin</th></tr></thead><tbody>
          {rows.map((row) => <tr key={`${row.product_id ?? 'snapshot'}-${row.item}-${row.category}`} className={Number(row.profit) < 0 ? 'margin-negative-row' : ''}><td><strong>{row.item}</strong>{row.missing_cost ? <small className="cost-warning"><AlertTriangle size={12} />Cost missing</small> : row.uses_current_cost ? <small className="cost-fallback">Using current product cost</small> : null}</td><td>{row.category}</td><td>{money(row.average_selling_price, currency)}</td><td>{money(row.average_cost_price, currency)}</td><td className={Number(row.unit_margin) < 0 ? 'negative' : 'positive'}>{money(row.unit_margin, currency)}</td><td>{qty(row.quantity)}</td><td>{money(row.net_sales, currency)}</td><td>{money(row.total_cost, currency)}</td><td className={Number(row.profit) < 0 ? 'negative' : 'positive'}>{money(row.profit, currency)}</td><td className={Number(row.margin_percent ?? 0) < 0 ? 'negative' : ''}>{row.margin_percent == null ? '—' : `${row.margin_percent}%`}</td></tr>)}
          {!rows.length && <tr><td colSpan={10} className="empty-row">No issued item sales match these filters.</td></tr>}
        </tbody></table></div>
      </section>
    </>}
  </div>
}

function MarginKpi({ icon, label, value, hint, tone }: { icon: ReactNode; label: string; value: string; hint: string; tone: string }) {
  return <article className={`dashboard-metric ${tone}`}><span>{icon}</span><small>{label}</small><strong>{value}</strong><em className="reports-kpi-hint">{hint}</em></article>
}
