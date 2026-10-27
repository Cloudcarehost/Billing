import { AlertTriangle, CheckCircle2, ChevronLeft, ChevronRight, ClipboardPlus, LoaderCircle, MessageSquareWarning, RefreshCw, Search, ShieldCheck, UserRoundCheck, XCircle } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import type { FormEvent, ReactNode } from 'react'
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Modal, Toast } from '../components/ui/Feedback'
import { useAuth } from '../features/auth/AuthContext'
import { can, canAny } from '../features/auth/permissions'
import { idempotencyHeaders } from '../features/realtime/useRestaurantRealtime'
import { api, errorMessage } from '../lib/api'
import { readable } from './opsShared'
import type { ApiEnvelope, ComplaintOptions, ComplaintOverview, ComplaintSource, ComplaintStaff, CustomerComplaint, Paginated } from '../types/api'

const DEFAULT_OPTIONS: ComplaintOptions = {
  statuses: ['open', 'under_review', 'resolved', 'dismissed'],
  reasons: ['taste_quality', 'preparation', 'temperature', 'wrong_item', 'missing_quantity', 'delay', 'hygiene', 'allergy_dietary', 'staff_behaviour', 'billing', 'other'],
  responsibility_areas: ['kitchen', 'waiter_service', 'management', 'system_process', 'customer', 'unknown'],
  outcomes: ['restaurant_issue', 'shared_responsibility', 'customer_misunderstanding', 'inconclusive'],
  resolution_types: ['item_replaced', 'apology_explanation', 'complimentary_item', 'discount_refund_separate', 'staff_coaching', 'no_action_required', 'other'],
  user_attributions: ['mentioned', 'responsible', 'cleared'],
}

type UserAttribution = { user_id: number; attribution: string; note: string }

function Heading({ action }: { action?: ReactNode }) {
  return <section className="page-heading"><div><p className="eyebrow">CUSTOMER CARE</p><h1>Customer complaints</h1><p>Register feedback against delivered items, investigate fairly, and track customer trust.</p></div>{action}</section>
}

function dateTime(value?: string | null) {
  if (!value) return '—'
  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' })
}

function number(value: number | null, suffix = '') {
  return value === null ? 'Not enough data' : `${value}${suffix}`
}

function statusTone(status: string) {
  if (status === 'resolved') return 'green'
  if (status === 'dismissed') return 'neutral'
  if (status === 'under_review') return 'amber'
  return 'red'
}

function selectedItemCount(quantities: Record<number, string>) {
  return Object.values(quantities).filter((quantity) => Number(quantity) > 0).length
}

export function ComplaintsPage() {
  const { session, activeOutletId } = useAuth()
  const mayView = can(session, 'complaints.view')
  const mayCreate = can(session, 'complaints.create')
  const mayManage = can(session, 'complaints.manage')
  const [options, setOptions] = useState(DEFAULT_OPTIONS)
  const [overview, setOverview] = useState<ComplaintOverview | null>(null)
  const [complaints, setComplaints] = useState<CustomerComplaint[]>([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [period, setPeriod] = useState('month')
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const [appliedSearch, setAppliedSearch] = useState('')
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(mayView)
  const [error, setError] = useState('')
  const [toast, setToast] = useState<{ message: string; tone: 'success' | 'error' } | null>(null)
  const [registerOpen, setRegisterOpen] = useState(false)
  const [detail, setDetail] = useState<CustomerComplaint | null>(null)

  const load = useCallback(async () => {
    if (!mayView) return
    setLoading(true)
    setError('')
    try {
      const params = { period, status: status || undefined, search: appliedSearch || undefined, page, per_page: 20, outlet_id: activeOutletId ?? undefined }
      const [summaryResponse, listResponse] = await Promise.all([
        api.get<ApiEnvelope<ComplaintOverview>>('/api/v1/complaints/overview', { params: { period, outlet_id: activeOutletId ?? undefined } }),
        api.get<Paginated<CustomerComplaint>>('/api/v1/complaints', { params }),
      ])
      setOverview(summaryResponse.data.data)
      setComplaints(listResponse.data.data)
      setMeta(listResponse.data.meta)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setLoading(false)
    }
  }, [activeOutletId, appliedSearch, mayView, page, period, status])

  useEffect(() => { const task = window.setTimeout(() => { void load() }, 0); return () => window.clearTimeout(task) }, [load])
  useEffect(() => {
    if (!canAny(session, ['complaints.view', 'complaints.create', 'complaints.manage'])) return
    void api.get<ApiEnvelope<ComplaintOptions>>('/api/v1/complaints/options').then((response) => setOptions(response.data.data)).catch(() => undefined)
  }, [session])
  useEffect(() => { const task = window.setTimeout(() => setPage(1), 0); return () => window.clearTimeout(task) }, [activeOutletId, period, status])

  async function openDetail(id: number) {
    setError('')
    try {
      const response = await api.get<ApiEnvelope<CustomerComplaint>>(`/api/v1/complaints/${id}`)
      setDetail(response.data.data)
    } catch (err) {
      setToast({ message: errorMessage(err), tone: 'error' })
    }
  }

  const submitSearch = (event: FormEvent) => {
    event.preventDefault()
    setPage(1)
    setAppliedSearch(search.trim())
  }

  return <div className="complaints-page">
    <Heading action={mayCreate ? <button type="button" className="button button-primary button-touch" onClick={() => setRegisterOpen(true)}><ClipboardPlus size={16} />Register complaint</button> : undefined} />
    {toast && <Toast message={toast.message} tone={toast.tone} onDismiss={() => setToast(null)} />}
    {error && <p className="form-error form-notice">{error}</p>}

    {!mayView && <section className="complaint-create-only"><MessageSquareWarning size={34} /><h2>Register customer feedback</h2><p>You can register a complaint against delivered items. Complaint history and investigation details are available to authorised managers.</p>{mayCreate && <button type="button" className="button button-primary button-touch" onClick={() => setRegisterOpen(true)}>Register complaint</button>}</section>}

    {mayView && <>
      <section className="complaint-metrics" aria-label="Complaint summary">
        <Metric icon={<MessageSquareWarning />} label="Complaints" value={overview?.metrics.total ?? 0} detail={`${overview?.metrics.open ?? 0} still open`} tone="red" />
        <Metric icon={<AlertTriangle />} label="Restaurant issues" value={overview?.metrics.restaurant_issues ?? 0} detail={`${overview?.metrics.shared_responsibility ?? 0} shared responsibility`} tone="amber" />
        <Metric icon={<ShieldCheck />} label="Reports supported" value={number(overview?.customer_trust.report_accuracy_percent ?? null, '%')} detail={overview?.customer_trust.sample_sufficient ? `${overview.customer_trust.reviewed_complaints} reviewed complaints` : 'Shown cautiously until 10 reviews'} tone="green" />
        <Metric icon={<UserRoundCheck />} label="Complaint rate" value={number(overview?.metrics.complaints_per_100_billed_items ?? null)} detail="Per 100 billed item units" tone="blue" />
      </section>

      <section className="complaint-insights">
        <article className="dashboard-card complaint-chart"><header><div><h2>Complaint trend</h2><p>Complaints and rate per 100 billed item units</p></div></header>
          {overview?.trend.some((point) => point.complaints > 0 || point.billed_item_units > 0) ? <ResponsiveContainer width="100%" height={250}><LineChart data={overview.trend} margin={{ left: -18, right: 12 }}><CartesianGrid strokeDasharray="3 3" stroke="#edf1f5" /><XAxis dataKey="label" fontSize={9} minTickGap={25} /><YAxis allowDecimals={false} fontSize={9} /><Tooltip /><Line type="monotone" dataKey="complaints" name="Complaints" stroke="#e74b4b" strokeWidth={2.5} dot={{ r: 3 }} /><Line type="monotone" dataKey="rate_per_100_items" name="Rate / 100 items" stroke="#2379ec" strokeWidth={2} connectNulls /></LineChart></ResponsiveContainer> : <p className="empty-row">No complaint activity in this period.</p>}
        </article>
        <article className="dashboard-card complaint-breakdown"><header><div><h2>What customers reported</h2><p>Most common complaint reasons</p></div></header>
          <div>{overview?.reasons.length ? overview.reasons.slice(0, 6).map((row) => <span key={row.label}><i>{readable(row.label)}</i><strong>{row.count}</strong></span>) : <p className="empty-row">No reasons recorded.</p>}</div>
        </article>
      </section>

      <section className="complaint-history data-card">
        <header className="complaint-toolbar"><div><h2>Complaint history</h2><p>{meta.total} complaint{meta.total === 1 ? '' : 's'} found</p></div>
          <form onSubmit={submitSearch} className="complaint-search"><Search size={15} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Complaint, table, item, customer…" aria-label="Search complaints" /><button type="submit" aria-label="Search">Search</button></form>
          <select value={period} onChange={(event) => setPeriod(event.target.value)} aria-label="Complaint period"><option value="today">Today</option><option value="last_7">Last 7 days</option><option value="month">This month</option><option value="last_month">Last month</option><option value="year">This year</option></select>
          <select value={status} onChange={(event) => setStatus(event.target.value)} aria-label="Complaint status"><option value="">All statuses</option>{options.statuses.map((item) => <option key={item} value={item}>{readable(item)}</option>)}</select>
          <button type="button" className="button button-secondary" disabled={loading} onClick={() => void load()}><RefreshCw size={15} />Refresh</button>
        </header>
        <div className="data-table"><table className="report-table complaint-table"><thead><tr><th>Complaint</th><th>Table / customer</th><th>Items</th><th>Reason</th><th>Against whom</th><th>Status</th><th /></tr></thead><tbody>
          {!loading && !complaints.length && <tr><td colSpan={7} className="empty-row">No complaints match these filters.</td></tr>}
          {complaints.map((complaint) => <tr key={complaint.id}><td><strong>{complaint.complaint_number}</strong><small>{dateTime(complaint.reported_at)}</small></td><td><strong>{complaint.table_name_snapshot}</strong><small>{complaint.customer_name_snapshot || complaint.outlet?.name || 'Walk-in customer'}</small></td><td><strong>{complaint.items.reduce((sum, item) => sum + Number(item.complained_quantity), 0)} unit(s)</strong><small>{complaint.items.map((item) => item.item_name_snapshot).join(', ')}</small></td><td>{readable(complaint.reason_code)}</td><td className="complaint-people-cell">{complaint.involved_users.length ? complaint.involved_users.map((entry) => <span key={entry.id}><strong>{entry.user_name_snapshot}</strong><small>{entry.role_name_snapshot || 'Staff'} · {readable(entry.attribution)}</small></span>) : <small>Not assigned</small>}</td><td><span className={`complaint-status ${statusTone(complaint.status)}`}>{readable(complaint.status)}</span></td><td><button type="button" className="button button-secondary" onClick={() => void openDetail(complaint.id)}>View</button></td></tr>)}
        </tbody></table></div>
        {meta.last_page > 1 && <footer className="complaint-pager"><button type="button" className="button button-secondary" disabled={page <= 1 || loading} onClick={() => setPage((current) => current - 1)}><ChevronLeft size={15} />Previous</button><span>Page {meta.current_page} of {meta.last_page}</span><button type="button" className="button button-secondary" disabled={page >= meta.last_page || loading} onClick={() => setPage((current) => current + 1)}>Next<ChevronRight size={15} /></button></footer>}
      </section>
    </>}

    <RegisterComplaintModal open={registerOpen} options={options} activeOutletId={activeOutletId} onClose={() => setRegisterOpen(false)} onCreated={(complaint) => { setRegisterOpen(false); setToast({ message: `${complaint.complaint_number} registered.`, tone: 'success' }); if (mayView) void load() }} />
    <ComplaintDetailModal complaint={detail} options={options} mayManage={mayManage} onClose={() => setDetail(null)} onChanged={(updated, message) => { setDetail(updated); setToast({ message, tone: 'success' }); if (mayView) void load() }} />
  </div>
}

function Metric({ icon, label, value, detail, tone }: { icon: ReactNode; label: string; value: ReactNode; detail: string; tone: string }) {
  return <article className={`complaint-metric ${tone}`}><span>{icon}</span><small>{label}</small><strong>{value}</strong><p>{detail}</p></article>
}

function RegisterComplaintModal({ open, options, activeOutletId, onClose, onCreated }: { open: boolean; options: ComplaintOptions; activeOutletId: number | null; onClose: () => void; onCreated: (complaint: CustomerComplaint) => void }) {
  const [sources, setSources] = useState<ComplaintSource[]>([])
  const [sourceSearch, setSourceSearch] = useState('')
  const [sourceDate, setSourceDate] = useState('')
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [reportedTableId, setReportedTableId] = useState<number | null>(null)
  const [quantities, setQuantities] = useState<Record<number, string>>({})
  const [reason, setReason] = useState(options.reasons[0] ?? 'other')
  const [statement, setStatement] = useState('')
  const [internalNote, setInternalNote] = useState('')
  const [responsibility, setResponsibility] = useState('unknown')
  const [staff, setStaff] = useState<ComplaintStaff[]>([])
  const [staffIds, setStaffIds] = useState<number[]>([])
  const [loadingSources, setLoadingSources] = useState(false)
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState('')
  const source = sources.find((item) => item.id === selectedId) ?? null

  const loadSources = useCallback(async (query = '', date = sourceDate) => {
    setLoadingSources(true)
    setFormError('')
    try {
      const response = await api.get<ApiEnvelope<ComplaintSource[]>>('/api/v1/complaints/sources', { params: { search: query || undefined, date: date || undefined, outlet_id: activeOutletId ?? undefined, limit: 30 } })
      setSources(response.data.data)
      if (selectedId && !response.data.data.some((item) => item.id === selectedId)) setSelectedId(null)
    } catch (err) {
      setFormError(errorMessage(err))
    } finally {
      setLoadingSources(false)
    }
  }, [activeOutletId, selectedId, sourceDate])

  useEffect(() => {
    if (!open) return
    const task = window.setTimeout(() => {
      setSelectedId(null); setReportedTableId(null); setQuantities({}); setStatement(''); setInternalNote(''); setStaffIds([]); setFormError(''); setResponsibility('unknown'); setSourceDate('')
      setReason(options.reasons[0] ?? 'other')
      void loadSources('', '')
    }, 0)
    return () => window.clearTimeout(task)
  // Reset only when the dialog opens; option/source updates must not erase the form.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  async function selectSource(next: ComplaintSource) {
    setSelectedId(next.id)
    setReportedTableId(next.table?.id ?? null)
    setQuantities({})
    setStaffIds([])
    setFormError('')
    try {
      const response = await api.get<ApiEnvelope<ComplaintStaff[]>>('/api/v1/complaints/staff', { params: { outlet_id: next.outlet.id } })
      setStaff(response.data.data)
    } catch (err) {
      setStaff([])
      setFormError(errorMessage(err))
    }
  }

  function toggleItem(itemId: number, maximum: string) {
    setQuantities((current) => ({ ...current, [itemId]: Number(current[itemId]) > 0 ? '' : maximum }))
  }

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!source || saving) return
    const allItems = source.orders.flatMap((order) => order.items)
    const items = allItems.filter((item) => Number(quantities[item.id]) > 0).map((item) => ({ order_item_id: item.id, quantity: Number(quantities[item.id]) }))
    if (!items.length) { setFormError('Select at least one delivered item.'); return }
    const invalid = items.find((entry) => entry.quantity <= 0 || entry.quantity > Number(allItems.find((item) => item.id === entry.order_item_id)?.quantity ?? 0))
    if (invalid) { setFormError('Each complained quantity must be greater than zero and no more than the ordered quantity.'); return }
    if (statement.trim().length < 3) { setFormError('Enter what the customer reported.'); return }
    setSaving(true)
    setFormError('')
    try {
      const response = await api.post<ApiEnvelope<CustomerComplaint>>('/api/v1/complaints', {
        dining_session_id: source.id,
        reported_table_id: reportedTableId ?? source.table?.id ?? undefined,
        reason_code: reason,
        customer_statement: statement.trim(),
        internal_note: internalNote.trim() || null,
        responsibility_area: responsibility,
        items,
        users: staffIds.map((user_id) => ({ user_id })),
      }, { headers: idempotencyHeaders() })
      onCreated(response.data.data)
    } catch (err) {
      setFormError(errorMessage(err))
    } finally {
      setSaving(false)
    }
  }

  return <Modal open={open} title="Register customer complaint" description="Choose the table or bill, then select the delivered items the customer complained about." onClose={() => !saving && onClose()}>
    <form className="complaint-form" onSubmit={submit}>
      {formError && <p className="form-error">{formError}</p>}
      <section className="complaint-form-section"><h3>1. Find the order</h3><div className="complaint-source-filters"><label className="complaint-date-filter"><span>Order date</span><input type="date" value={sourceDate} onChange={(event) => setSourceDate(event.target.value)} /></label>{sourceDate && <button type="button" className="complaint-clear-date" onClick={() => { setSourceDate(''); void loadSources(sourceSearch.trim(), '') }}>Clear date</button>}</div><div className="complaint-source-search"><Search size={15} /><input value={sourceSearch} onChange={(event) => setSourceSearch(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); if (!loadingSources) void loadSources(sourceSearch.trim(), sourceDate) } }} placeholder="Table, ticket, invoice or customer" /><button type="button" disabled={loadingSources} aria-busy={loadingSources} onClick={() => void loadSources(sourceSearch.trim(), sourceDate)}>{loadingSources ? <><LoaderCircle className="icon-spin" size={14} />Finding…</> : 'Find'}</button></div>
        <div className="complaint-source-list">{sources.map((item) => <button key={item.id} type="button" className={selectedId === item.id ? 'active' : ''} onClick={() => void selectSource(item)}><span><strong>{item.display_name}</strong><small>{item.outlet.name} · {item.invoice ? `Invoice ${item.invoice.invoice_number} · ${item.invoice.business_date ?? ''}` : `${readable(item.status)} · ${new Date(item.opened_at).toLocaleDateString('en-IN')}`}</small></span><b>{item.orders.reduce((sum, order) => sum + order.items.length, 0)} items</b></button>)}{!loadingSources && !sources.length && <p>No matching orders for this date.</p>}</div>
      </section>

      {source && <><section className="complaint-form-section"><h3>2. Select complained items</h3>{source.joined_tables.length > 0 && <label className="field"><span>Physical table that raised the complaint</span><select value={reportedTableId ?? ''} onChange={(event) => setReportedTableId(Number(event.target.value))}>{source.table && <option value={source.table.id}>{source.table.name}</option>}{source.joined_tables.map((table) => <option key={table.id} value={table.id}>{table.name}</option>)}</select></label>}<div className="complaint-item-picker">{source.orders.flatMap((order) => order.items.map((item) => <label key={item.id} className={Number(quantities[item.id]) > 0 ? 'selected' : ''}><input type="checkbox" checked={Number(quantities[item.id]) > 0} onChange={() => toggleItem(item.id, item.quantity)} /><span><strong>{item.item_name}</strong><small>{order.ticket_number} · {readable(item.delivery_evidence)}</small></span><input type="number" aria-label={`Complaint quantity for ${item.item_name}`} min="0.001" max={item.quantity} step="0.001" value={quantities[item.id] ?? ''} disabled={!Number(quantities[item.id])} onChange={(event) => setQuantities((current) => ({ ...current, [item.id]: event.target.value }))} /></label>))}</div><small className="complaint-help">{selectedItemCount(quantities)} item line(s) selected. “Staff confirmation required” is shown where the order has no recorded served timestamp or final bill.</small></section>
        <section className="complaint-form-section"><h3>3. Record the complaint</h3><div className="complaint-form-grid"><label className="field"><span>Complaint reason</span><select value={reason} onChange={(event) => setReason(event.target.value)}>{options.reasons.map((item) => <option key={item} value={item}>{readable(item)}</option>)}</select></label><label className="field"><span>Initial responsibility</span><select value={responsibility} onChange={(event) => setResponsibility(event.target.value)}>{options.responsibility_areas.map((item) => <option key={item} value={item}>{readable(item)}</option>)}</select></label><label className="field field-wide"><span>What did the customer report?</span><textarea rows={3} maxLength={5000} required value={statement} onChange={(event) => setStatement(event.target.value)} /></label><label className="field field-wide"><span>Internal note (optional)</span><textarea rows={2} maxLength={5000} value={internalNote} onChange={(event) => setInternalNote(event.target.value)} /></label></div></section>
        <section className="complaint-form-section"><h3>4. Staff mentioned (optional)</h3><div className="complaint-staff-picker">{staff.map((member) => <label key={member.id}><input type="checkbox" checked={staffIds.includes(member.id)} onChange={() => setStaffIds((current) => current.includes(member.id) ? current.filter((id) => id !== member.id) : [...current, member.id])} /><span><strong>{member.name}</strong><small>{member.role}</small></span></label>)}{!staff.length && <p>No selectable staff for this outlet.</p>}</div></section></>}
      <div className="form-action-row complaint-form-actions"><button type="button" className="button button-secondary button-touch" disabled={saving} onClick={onClose}>Cancel</button><button type="submit" className="button button-primary button-touch" disabled={!source || selectedItemCount(quantities) === 0 || saving}>{saving ? 'Registering…' : 'Register complaint'}</button></div>
    </form>
  </Modal>
}

function ComplaintDetailModal({ complaint, options, mayManage, onClose, onChanged }: { complaint: CustomerComplaint | null; options: ComplaintOptions; mayManage: boolean; onClose: () => void; onChanged: (complaint: CustomerComplaint, message: string) => void }) {
  const [note, setNote] = useState('')
  const [outcome, setOutcome] = useState('restaurant_issue')
  const [responsibility, setResponsibility] = useState('unknown')
  const [resolutionType, setResolutionType] = useState('apology_explanation')
  const [resolutionNote, setResolutionNote] = useState('')
  const [staff, setStaff] = useState<ComplaintStaff[]>([])
  const [users, setUsers] = useState<UserAttribution[]>([])
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState('')

  useEffect(() => {
    if (!complaint) return
    const task = window.setTimeout(() => {
      setNote(''); setResolutionNote(''); setFormError('')
      setOutcome(complaint.outcome ?? 'restaurant_issue')
      setResponsibility(complaint.responsibility_area ?? 'unknown')
      setResolutionType(complaint.resolution_type ?? 'apology_explanation')
      setUsers(complaint.involved_users.map((entry) => ({ user_id: entry.user_id ?? entry.user?.id ?? 0, attribution: entry.attribution, note: entry.note ?? '' })).filter((entry) => entry.user_id > 0))
      if (mayManage && complaint.outlet?.id) void api.get<ApiEnvelope<ComplaintStaff[]>>('/api/v1/complaints/staff', { params: { outlet_id: complaint.outlet.id } }).then((response) => setStaff(response.data.data)).catch(() => setStaff([]))
    }, 0)
    return () => window.clearTimeout(task)
  }, [complaint, mayManage])

  async function action(path: string, payload: Record<string, unknown>, message: string) {
    if (!complaint || saving) return
    setSaving(true); setFormError('')
    try {
      const response = await api.post<ApiEnvelope<CustomerComplaint>>(`/api/v1/complaints/${complaint.id}/${path}`, payload)
      onChanged(response.data.data, message)
      setNote(''); setResolutionNote('')
    } catch (err) {
      setFormError(errorMessage(err))
    } finally {
      setSaving(false)
    }
  }

  async function markUnderReview() {
    if (!complaint || saving) return
    setSaving(true); setFormError('')
    try {
      const response = await api.patch<ApiEnvelope<CustomerComplaint>>(`/api/v1/complaints/${complaint.id}`, { status: 'under_review', change_note: 'Investigation started.' })
      onChanged(response.data.data, 'Complaint moved under review.')
    } catch (err) { setFormError(errorMessage(err)) } finally { setSaving(false) }
  }

  function toggleStaff(member: ComplaintStaff) {
    setUsers((current) => current.some((entry) => entry.user_id === member.id) ? current.filter((entry) => entry.user_id !== member.id) : [...current, { user_id: member.id, attribution: 'mentioned', note: '' }])
  }

  if (!complaint) return null
  const closed = complaint.status === 'resolved' || complaint.status === 'dismissed'
  return <Modal open title={complaint.complaint_number} description={`${complaint.table_name_snapshot} · ${dateTime(complaint.reported_at)}`} onClose={() => !saving && onClose()}>
    <div className="complaint-detail">
      {formError && <p className="form-error">{formError}</p>}
      <div className="complaint-detail-head"><span className={`complaint-status ${statusTone(complaint.status)}`}>{readable(complaint.status)}</span><span>{readable(complaint.reason_code)}</span></div>
      <section><h3>Customer report</h3><p>{complaint.customer_statement}</p>{complaint.internal_note && <small>Internal note: {complaint.internal_note}</small>}</section>
      <section><h3>Items</h3><div className="complaint-detail-items">{complaint.items.map((item) => <span key={item.id}><i><strong>{item.item_name_snapshot}</strong><small>{item.ticket_number_snapshot}</small></i><b>{Number(item.complained_quantity)} / {Number(item.ordered_quantity)}</b></span>)}</div></section>
      <section><h3>People mentioned</h3><div className="complaint-detail-tags">{complaint.involved_users.length ? complaint.involved_users.map((entry) => <span key={entry.id}>{entry.user_name_snapshot} · {readable(entry.attribution)}</span>) : <small>No staff member was mentioned.</small>}</div></section>
      {complaint.resolution_note && <section className="complaint-resolution"><h3>Resolution</h3><p>{complaint.resolution_note}</p><small>{readable(complaint.outcome ?? '')} · {readable(complaint.responsibility_area ?? '')} · {readable(complaint.resolution_type ?? '')}</small></section>}
      {complaint.events?.length ? <section><h3>Timeline</h3><div className="complaint-timeline">{complaint.events.map((event) => <div key={event.id}><i /><span><strong>{readable(event.event_type)}</strong><small>{event.actor?.name ?? 'System'} · {dateTime(event.occurred_at)}</small>{event.note && <p>{event.note}</p>}</span></div>)}</div></section> : null}

      {mayManage && !closed && <section className="complaint-manage"><h3>Investigation</h3>{complaint.status === 'open' && <button type="button" className="button button-secondary" disabled={saving} onClick={() => void markUnderReview()}>Mark under review</button>}<label className="field"><span>Add investigation note</span><textarea rows={2} maxLength={5000} value={note} onChange={(event) => setNote(event.target.value)} /></label><button type="button" className="button button-secondary" disabled={saving || !note.trim()} onClick={() => void action('events', { note: note.trim() }, 'Investigation note added.')}>Add note</button>
        <div className="complaint-resolution-form"><label className="field"><span>Outcome</span><select value={outcome} onChange={(event) => setOutcome(event.target.value)}>{options.outcomes.map((item) => <option key={item} value={item}>{readable(item)}</option>)}</select></label><label className="field"><span>Responsibility</span><select value={responsibility} onChange={(event) => setResponsibility(event.target.value)}>{options.responsibility_areas.map((item) => <option key={item} value={item}>{readable(item)}</option>)}</select></label><label className="field"><span>Resolution</span><select value={resolutionType} onChange={(event) => setResolutionType(event.target.value)}>{options.resolution_types.map((item) => <option key={item} value={item}>{readable(item)}</option>)}</select></label><label className="field field-wide"><span>Resolution note</span><textarea rows={3} maxLength={5000} value={resolutionNote} onChange={(event) => setResolutionNote(event.target.value)} /></label></div>
        <div className="complaint-attribution"><h3>Staff attribution</h3>{staff.map((member) => { const entry = users.find((item) => item.user_id === member.id); return <div key={member.id}><label><input type="checkbox" checked={Boolean(entry)} onChange={() => toggleStaff(member)} /><span><strong>{member.name}</strong><small>{member.role}</small></span></label>{entry && <select value={entry.attribution} onChange={(event) => setUsers((current) => current.map((item) => item.user_id === member.id ? { ...item, attribution: event.target.value } : item))}>{options.user_attributions.map((item) => <option key={item} value={item}>{readable(item)}</option>)}</select>}</div>})}</div>
        <div className="complaint-manage-actions"><button type="button" className="button button-primary button-touch" disabled={saving || resolutionNote.trim().length < 3} onClick={() => void action('resolve', { outcome, responsibility_area: responsibility, resolution_type: resolutionType, resolution_note: resolutionNote.trim(), users }, 'Complaint resolved.')}>{saving ? 'Saving…' : <><CheckCircle2 size={15} />Resolve</>}</button><button type="button" className="button button-danger-soft button-touch" disabled={saving || resolutionNote.trim().length < 3} onClick={() => void action('dismiss', { outcome: outcome === 'customer_misunderstanding' ? outcome : 'inconclusive', reason: resolutionNote.trim() }, 'Complaint dismissed.')}><XCircle size={15} />Dismiss</button></div>
      </section>}
      {mayManage && closed && <section className="complaint-manage"><label className="field"><span>Reason for reopening</span><textarea rows={2} value={resolutionNote} onChange={(event) => setResolutionNote(event.target.value)} /></label><button type="button" className="button button-secondary button-touch" disabled={saving || resolutionNote.trim().length < 3} onClick={() => void action('reopen', { reason: resolutionNote.trim() }, 'Complaint reopened.')}>Reopen complaint</button></section>}
    </div>
  </Modal>
}
