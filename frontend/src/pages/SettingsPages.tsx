import { Check, CirclePlus, MonitorSmartphone, Pencil, ShieldAlert, ShieldCheck, Store, Trash2, UserPlus, Users } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import type { FormEvent, InputHTMLAttributes, ReactNode } from 'react'
import { Modal, ConfirmDialog } from '../components/ui/Feedback'
import { useAuth } from '../features/auth/AuthContext'
import { can, isOwner } from '../features/auth/permissions'
import { api, errorMessage } from '../lib/api'
import { FLOOR_SOUND_PREVIEWS, playFloorSound } from '../features/notifications/floorAlerts'
import type { ApiEnvelope, AuditLog, DeviceSession, Hotel, Outlet, Paginated, Permission, Role, StaffMember } from '../types/api'

function Header({ eyebrow, title, text, action }: { eyebrow: string; title: string; text: string; action?: ReactNode }) { return <section className="page-heading"><div><p className="eyebrow">{eyebrow}</p><h1>{title}</h1><p>{text}</p></div>{action}</section> }
function Notice({ error, success }: { error?: string; success?: string }) { return <>{error && <p className="form-error form-notice">{error}</p>}{success && <p className="form-success form-notice"><Check size={16} />{success}</p>}</> }

export function HotelSettingsPage() {
  const { session, setSession } = useAuth(); const [hotel, setHotel] = useState<Hotel | null>(null); const [message, setMessage] = useState(''); const [error, setError] = useState('')
  useEffect(() => { const task = window.setTimeout(() => { void api.get<ApiEnvelope<Hotel>>('/api/v1/hotel').then((response) => setHotel(response.data.data)).catch((err) => setError(errorMessage(err))) }, 0); return () => window.clearTimeout(task) }, [])
  async function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); if (!hotel) return; const values = Object.fromEntries(new FormData(event.currentTarget)); setError(''); try { const response = await api.put<ApiEnvelope<Hotel>>('/api/v1/hotel', values); setHotel(response.data.data); if (session) setSession({ ...session, hotel: { ...session.hotel, ...response.data.data } }); setMessage('Hotel settings saved.') } catch (err) { setError(errorMessage(err)) } }
  return <><Header eyebrow="SETTINGS" title="Hotel profile" text="Set the legal, contact and financial defaults used across your restaurant." /><form className="settings-card" onSubmit={submit}>{hotel ? <div className="form-grid"><Input label="Hotel name" name="name" defaultValue={hotel.name} /><Input label="Legal business name" name="legal_name" defaultValue={hotel.legal_name ?? ''} /><Input label="Email" name="email" type="email" defaultValue={hotel.email ?? ''} /><Input label="Phone" name="phone" defaultValue={hotel.phone ?? ''} /><Input label="Tax number" name="tax_number" defaultValue={hotel.tax_number ?? ''} /><Input label="GSTIN" name="gstin" defaultValue={hotel.gstin ?? ''} /><Input label="GST state code" name="state_code" defaultValue={hotel.state_code ?? ''} maxLength={2} /><Input label="Currency" name="currency_code" defaultValue={hotel.currency_code} maxLength={3} /><Input label="Timezone" name="timezone" defaultValue={hotel.timezone} /><Input label="Business day starts at" name="business_day_starts_at" type="time" defaultValue={(hotel.business_day_starts_at ?? '05:00:00').slice(0, 5)} /><label className="field"><span>Inventory deduction (optional tracking only)</span><select name="inventory_deduction_rule" defaultValue={hotel.inventory_deduction_rule ?? 'preparing'}><option value="preparing">When kitchen starts prep / direct items are sent</option><option value="served">When the item is marked served</option></select></label><label className="check-row field-wide"><input type="hidden" name="allow_negative_stock" value="0" /><input type="checkbox" name="allow_negative_stock" value="1" defaultChecked={hotel.allow_negative_stock} />Allow negative stock in inventory adjustments (orders are never blocked by stock)</label><SoundPick label="Kitchen new order" name="alert_sound_kitchen" value={hotel.alert_sound_kitchen ?? 'chirp'} /><SoundPick label="Waiter: food ready" name="alert_sound_ready" value={hotel.alert_sound_ready ?? 'waiter'} /><SoundPick label="Waiter: guest call" name="alert_sound_call" value={hotel.alert_sound_call ?? 'waiter'} /><label className="field field-wide"><span>Address</span><textarea name="address" defaultValue={hotel.address ?? ''} rows={3} /></label></div> : <LoadingRows /> }<Notice error={error} success={message} /><button className="button button-primary" disabled={!hotel}>Save hotel settings</button></form></>
}

export function AccountSecurityPage() {
  const { session } = useAuth(); const [error, setError] = useState(''); const [success, setSuccess] = useState(''); const [submitting, setSubmitting] = useState(false); const [devices, setDevices] = useState<DeviceSession[]>([]); const [audit, setAudit] = useState<AuditLog[]>([])
  const owner = isOwner(session)
  const loadSecurity = useCallback(async () => { try { const deviceData = await api.get<ApiEnvelope<DeviceSession[]>>('/api/v1/security/sessions'); setDevices(deviceData.data.data); if (owner) { const auditData = await api.get<Paginated<AuditLog>>('/api/v1/audit-logs?per_page=20'); setAudit(auditData.data.data) } } catch (err) { setError(errorMessage(err)) } }, [owner])
  useEffect(() => { const task = window.setTimeout(() => { void loadSecurity() }, 0); return () => window.clearTimeout(task) }, [loadSecurity])
  async function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); const form = event.currentTarget; const values = Object.fromEntries(new FormData(form)); setError(''); setSuccess(''); setSubmitting(true); try { await api.put('/api/v1/me/password', values); form.reset(); setSuccess('Password changed successfully.') } catch (err) { setError(errorMessage(err)) } finally { setSubmitting(false) } }
  async function revoke(id: number) { try { await api.delete(`/api/v1/security/sessions/${id}`); setSuccess('Device session revoked.'); await loadSecurity() } catch (err) { setError(errorMessage(err)) } }
  async function revokeOthers() { try { await api.delete('/api/v1/security/sessions'); setSuccess('Other device sessions revoked.'); await loadSecurity() } catch (err) { setError(errorMessage(err)) } }
  return <><Header eyebrow="ACCOUNT" title="Account security" text="Manage your password, active devices and owner audit activity." /><form className="settings-card" onSubmit={submit}><div className="form-grid"><Input label="Current password" name="current_password" type="password" required /><Input label="New password" name="password" type="password" minLength={8} required /><Input label="Confirm new password" name="password_confirmation" type="password" minLength={8} required /></div><Notice error={error} success={success} /><button className="button button-primary" disabled={submitting}>{submitting ? 'Saving...' : 'Change password'}</button></form><section className="data-card settings-section"><div className="data-card-heading"><MonitorSmartphone size={19} /><h2>Your signed-in devices</h2>{devices.length > 1 && <button className="button button-secondary" onClick={() => void revokeOthers()}>Revoke other devices</button>}</div><div className="data-list">{devices.map((device) => <div className="data-row session-row" key={device.id}><span className="row-icon"><MonitorSmartphone size={17} /></span><div><strong>{device.device_name || 'Browser session'}</strong><small>{device.ip_address || 'Unknown IP'} · Last active {device.last_seen_at ? new Date(device.last_seen_at).toLocaleString() : '—'}</small></div>{device.revoked_at ? <span className="status-badge status-red">Revoked</span> : <button className="row-action" title="Revoke this device" onClick={() => void revoke(device.id)}><Trash2 size={16} /></button>}</div>)}{!devices.length && <Empty text="No tracked device sessions yet." />}</div></section>{owner && <section className="data-card settings-section"><div className="data-card-heading"><ShieldAlert size={19} /><h2>Recent audit activity</h2><span>Owner only</span></div><div className="data-list">{audit.map((entry) => <div className="data-row" key={entry.id}><span className="row-icon"><ShieldCheck size={17} /></span><div><strong>{entry.action.replaceAll('_', ' ')}</strong><small>{entry.user?.name ?? 'System'} · {entry.outlet?.name ?? 'Hotel'} · {new Date(entry.occurred_at).toLocaleString()}</small></div><span className="audit-properties">{entry.properties ? JSON.stringify(entry.properties) : ''}</span></div>)}{!audit.length && <Empty text="No audit activity recorded yet." />}</div></section>}</>
}

export function OutletsPage() {
  const { refresh } = useAuth()
  const [outlets, setOutlets] = useState<Outlet[]>([])
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [adding, setAdding] = useState(false)
  const [editing, setEditing] = useState<Outlet | null>(null)
  const [pendingSwitch, setPendingSwitch] = useState<FormData | null>(null)
  const [deleting, setDeleting] = useState<Outlet | null>(null)
  const load = () => api.get<ApiEnvelope<Outlet[]>>('/api/v1/outlets').then((response) => setOutlets(response.data.data)).catch((err) => setError(errorMessage(err)))
  useEffect(() => { const task = window.setTimeout(() => { void load() }, 0); return () => window.clearTimeout(task) }, [])
  function closeForm() { setAdding(false); setEditing(null); setPendingSwitch(null) }
  async function saveOutlet(values: FormData) {
    const data = {
      name: String(values.get('name')),
      code: String(values.get('code')),
      invoice_prefix: String(values.get('invoice_prefix')),
      address: String(values.get('address') || '') || null,
      is_active: values.has('is_active'),
      order_flow: String(values.get('order_flow') || 'kitchen'),
    }
    setError('')
    try {
      if (editing) await api.put(`/api/v1/outlets/${editing.id}`, data)
      else await api.post('/api/v1/outlets', data)
      const wasEditing = Boolean(editing)
      closeForm()
      setMessage(wasEditing ? 'Outlet settings updated.' : 'Outlet created.')
      await load()
      await refresh()
    } catch (err) {
      setError(errorMessage(err))
    }
  }
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = event.currentTarget
    const values = new FormData(form)
    const nextFlow = String(values.get('order_flow') || 'kitchen')
    if (editing && editing.order_flow !== 'direct_bill' && nextFlow === 'direct_bill') {
      setPendingSwitch(values)
      return
    }
    await saveOutlet(values)
  }
  async function deleteOutlet() {
    if (!deleting) return
    setError('')
    try {
      await api.delete(`/api/v1/outlets/${deleting.id}`)
      setMessage(`${deleting.name} deleted.`)
      setDeleting(null)
      closeForm()
      await load()
      await refresh()
    } catch (err) {
      setError(errorMessage(err))
      setDeleting(null)
    }
  }
  const formOpen = adding || editing !== null
  const onlyOutlet = outlets.length <= 1
  return <>
    <Header eyebrow="SETTINGS" title="Outlets" text="Set up each place that serves or bills guests." action={<button className="button button-primary" type="button" onClick={() => { setEditing(null); setAdding(true); setMessage('') }}><CirclePlus size={17} />Add outlet</button>} />
    <Modal open={formOpen} title={editing ? `Edit ${editing.name}` : 'Add outlet'} description={editing ? 'Update how this outlet appears on tickets and invoices.' : 'Create another restaurant, bar or counter that bills separately.'} onClose={closeForm}>
      <form key={editing?.id ?? 'new'} className="compact-form" onSubmit={submit}>
        <div className="form-grid">
          <Input label="Outlet name" name="name" required defaultValue={editing?.name ?? ''} />
          <Input label="Outlet code" name="code" required defaultValue={editing?.code ?? ''} />
          <Input label="Invoice prefix" name="invoice_prefix" required defaultValue={editing?.invoice_prefix ?? 'INV'} />
          <Input label="Address" name="address" defaultValue={editing?.address ?? ''} />
          <label className="field"><span>Order flow</span><select name="order_flow" defaultValue={editing?.order_flow ?? 'kitchen'}><option value="kitchen">Kitchen (current steps)</option><option value="direct_bill">Direct to bill (skip kitchen)</option></select></label>
          <label className="check-row field-wide"><input type="checkbox" name="is_active" value="1" defaultChecked={editing?.is_active ?? true} />Active outlet</label>
        </div>
        <div className="form-action-row">
          <button className="button button-primary" type="submit">{editing ? 'Save outlet' : 'Create outlet'}</button>
          <button className="button button-secondary" type="button" onClick={closeForm}>Close</button>
        </div>
      </form>
    </Modal>
    <ConfirmDialog open={Boolean(pendingSwitch)} title="Switch this outlet to direct to bill?" text="Open kitchen tickets for this outlet will leave the kitchen and go onto the bill. New orders skip kitchen and serve statuses until you switch back." confirmLabel="Switch to direct to bill" onClose={() => setPendingSwitch(null)} onConfirm={() => { if (pendingSwitch) void saveOutlet(pendingSwitch) }} />
    <ConfirmDialog open={Boolean(deleting)} title={deleting ? `Delete ${deleting.name}?` : 'Delete outlet'} text="Empty outlets can be removed. If this outlet has tables, bills or history, deactivate it instead so past invoices stay intact." confirmLabel="Delete outlet" onClose={() => setDeleting(null)} onConfirm={() => void deleteOutlet()} />
    <Notice error={error} success={message} />
    <section className="data-card">
      <div className="data-card-heading"><Store size={19} /><h2>Your outlets</h2><span>{outlets.length} total</span></div>
      <div className="data-list">{outlets.map((outlet) => <div className="data-row" key={outlet.id}><span className="row-icon"><Store size={18} /></span><div><strong>{outlet.name}</strong><small>{outlet.code} · Invoice prefix {outlet.invoice_prefix} · {outlet.order_flow === 'direct_bill' ? 'Direct to bill' : 'Kitchen flow'}</small></div><span className={`status-badge ${outlet.is_active ? 'status-green' : 'status-red'}`}>{outlet.is_active ? 'Active' : 'Inactive'}</span><span className="row-actions"><button className="row-action" type="button" title={`Edit ${outlet.name}`} onClick={() => { setAdding(false); setEditing(outlet); setMessage(''); setError('') }}><Pencil size={16} /></button><button className="row-action danger" type="button" disabled={onlyOutlet} title={onlyOutlet ? 'Keep at least one outlet' : `Delete ${outlet.name}`} onClick={() => { setDeleting(outlet); setError(''); setMessage('') }}><Trash2 size={16} /></button></span></div>)}{!outlets.length && <Empty text="No outlets found." />}</div>
    </section>
  </>
}

export function StaffPage() {
  const { session } = useAuth(); const [staff, setStaff] = useState<StaffMember[]>([]); const [roles, setRoles] = useState<Role[]>([]); const [adding, setAdding] = useState(false); const [error, setError] = useState(''); const [message, setMessage] = useState('')
  const mayManageStaff = can(session, 'users.manage')
  const load = async () => { try { const [users, roleData] = await Promise.all([api.get<Paginated<StaffMember>>('/api/v1/users'), api.get<ApiEnvelope<Role[]>>('/api/v1/roles')]); setStaff(users.data.data); setRoles(roleData.data.data) } catch (err) { setError(errorMessage(err)) } }
  useEffect(() => { const task = window.setTimeout(() => { void load() }, 0); return () => window.clearTimeout(task) }, [])
  async function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); const form = event.currentTarget; const values = new FormData(form); const outletIds = values.getAll('outlet_ids').map(Number); if (!outletIds.length) { setError('Select at least one outlet for this staff member.'); return } const data = { name: String(values.get('name')), email: String(values.get('email')), password: String(values.get('password')), role_id: Number(values.get('role_id')), outlet_ids: outletIds }; setError(''); try { await api.post('/api/v1/users', data); form.reset(); setAdding(false); setMessage('Staff access created.'); await load() } catch (err) { setError(errorMessage(err)) } }
  return <><Header eyebrow="SETTINGS" title="Staff access" text="Invite your team and assign only the outlets they work in." action={mayManageStaff ? <button className="button button-primary" onClick={() => setAdding(!adding)}><UserPlus size={17} />Add staff</button> : undefined} />{adding && mayManageStaff && <form className="settings-card compact-form" onSubmit={submit}><div className="form-grid"><Input label="Full name" name="name" required /><Input label="Email" name="email" type="email" required /><Input label="Temporary password" name="password" type="password" required minLength={8} /><label className="field"><span>Role</span><select name="role_id" required defaultValue=""><option value="" disabled>Select a role</option>{roles.map((role) => <option value={role.id} key={role.id}>{role.name}</option>)}</select></label></div><fieldset className="outlet-assignment"><legend>Outlet access</legend><p>Select every outlet this person may open and receive realtime updates for.</p><div>{session?.outlets.map((outlet) => <label key={outlet.id}><input type="checkbox" name="outlet_ids" value={outlet.id} defaultChecked={session.outlets.length === 1} /><span>{outlet.name}</span></label>)}</div></fieldset><button className="button button-primary">Create staff access</button></form>}<Notice error={error} success={message} /><section className="data-card"><div className="data-card-heading"><Users size={19} /><h2>Team members</h2><span>{staff.length} total</span></div><div className="data-list">{staff.map((member) => <div className="data-row" key={member.id}><span className="member-avatar">{member.name.slice(0, 1)}</span><div><strong>{member.name}</strong><small>{member.email}</small></div><span className="role-pill">{member.membership.role?.name ?? 'No role'}</span><small>{member.membership.outlet_ids?.length ?? 0} outlet{member.membership.outlet_ids?.length === 1 ? '' : 's'}</small><span className={`status-badge ${member.membership.is_active ? 'status-green' : 'status-red'}`}>{member.membership.is_active ? 'Active' : 'Inactive'}</span></div>)}{!staff.length && <Empty text="No staff members found." />}</div></section></>
}

export function RolesPage() {
  const { session } = useAuth(); const [roles, setRoles] = useState<Role[]>([]); const [permissions, setPermissions] = useState<Permission[]>([]); const [adding, setAdding] = useState(false); const [editingRole, setEditingRole] = useState<Role | null>(null); const [error, setError] = useState(''); const [message, setMessage] = useState('')
  const owner = isOwner(session)
  const load = async () => { try { const [roleData, permissionData] = await Promise.all([api.get<ApiEnvelope<Role[]>>('/api/v1/roles'), api.get<ApiEnvelope<Permission[]>>('/api/v1/permissions')]); setRoles(roleData.data.data); setPermissions(permissionData.data.data) } catch (err) { setError(errorMessage(err)) } }
  useEffect(() => { const task = window.setTimeout(() => { void load() }, 0); return () => window.clearTimeout(task) }, [])
  function closeForm() { setAdding(false); setEditingRole(null) }
  async function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); const formElement = event.currentTarget; const form = new FormData(formElement); const data = { name: String(form.get('name') ?? ''), permission_ids: form.getAll('permission_ids').map(Number) }; setError(''); setMessage(''); try { if (editingRole) await api.put(`/api/v1/roles/${editingRole.id}`, data); else await api.post('/api/v1/roles', data); formElement.reset(); const wasEditing = Boolean(editingRole); closeForm(); setMessage(wasEditing ? 'Role permissions saved.' : 'Custom role created.'); await load() } catch (err) { setError(errorMessage(err)) } }
  const groups = permissions.reduce<Record<string, Permission[]>>((result, permission) => ({ ...result, [permission.group]: [...(result[permission.group] ?? []), permission] }), {})
  const isFormOpen = adding || editingRole !== null
  return <><Header eyebrow="SETTINGS" title="Roles & permissions" text="Roles control exactly what your team can access in the restaurant." action={owner ? <button className="button button-primary" onClick={() => { setEditingRole(null); setAdding(true) }}><CirclePlus size={17} />Create role</button> : undefined} />{isFormOpen && owner && <form key={editingRole?.id ?? 'new'} className="settings-card" onSubmit={submit}><div className="role-form-heading"><div><h2>{editingRole ? `Edit ${editingRole.name}` : 'Create role'}</h2><p>Select exactly what this role can see and do.</p></div><button className="button button-secondary" type="button" onClick={closeForm}>Cancel</button></div><Input label="Role name" name="name" required defaultValue={editingRole?.name ?? ''} /><div className="permission-groups">{Object.entries(groups).map(([group, items]) => <section key={group}><h3>{group}</h3>{items.map((permission) => <label className="permission-check" key={permission.id}><input name="permission_ids" value={permission.id} type="checkbox" defaultChecked={editingRole?.permissions?.some((assigned) => assigned.id === permission.id) ?? false} /><span><strong>{permission.name}</strong><small>{permission.code}</small></span></label>)}</section>)}</div><div className="form-action-row"><button className="button button-primary">{editingRole ? 'Save permissions' : 'Create role'}</button><button className="button button-secondary" type="button" onClick={closeForm}>Cancel</button></div></form>}<Notice error={error} success={message} /><section className="role-grid">{roles.map((role) => <article className="role-card" key={role.id}><span className="role-icon"><ShieldCheck size={20} /></span><div><h2>{role.name}</h2><p>{role.is_owner ? 'Full access to every area of this hotel.' : `${role.permissions?.length ?? 0} assigned permissions`}</p></div><span className={role.is_owner ? 'owner-badge' : 'role-pill'}>{role.is_owner ? 'System Owner' : role.slug}</span>{role.permissions && <div className="permission-tags">{role.permissions.slice(0, 4).map((permission) => <span key={permission.id}>{permission.name}</span>)}{role.permissions.length > 4 && <span>+{role.permissions.length - 4} more</span>}</div>}{owner && !role.is_owner && <div className="role-card-actions"><button className="button button-secondary" type="button" onClick={() => { setAdding(false); setEditingRole(role) }}><Pencil size={15} />Edit permissions</button></div>}</article>)}</section></>
}

function SoundPick({ label, name, value }: { label: string; name: string; value: string }) {
  return <label className="field"><span>{label}</span><span className="sound-pick"><select name={name} defaultValue={value}>{FLOOR_SOUND_PREVIEWS.map((option) => <option key={option.id} value={option.id}>{option.label}</option>)}</select><button type="button" className="button button-secondary" onClick={(event) => { const select = (event.currentTarget.previousElementSibling as HTMLSelectElement | null); playFloorSound((select?.value ?? value) as typeof FLOOR_SOUND_PREVIEWS[number]['id']) }}>Hear</button></span></label>
}
function Input({ label, ...props }: { label: string } & InputHTMLAttributes<HTMLInputElement>) { return <label className="field"><span>{label}</span><input {...props} /></label> }
function Empty({ text }: { text: string }) { return <p className="empty-row">{text}</p> }
function LoadingRows() { return <p className="empty-row">Loading hotel settings...</p> }
