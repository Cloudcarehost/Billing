import axios from 'axios'
import { BellRing, CheckCircle2, Download, ExternalLink, Printer, QrCode, RefreshCw, ShieldCheck, WifiOff } from 'lucide-react'
import QRCode from 'qrcode'
import { useCallback, useEffect, useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { ConfirmDialog } from '../components/ui/Feedback'
import { api, errorMessage } from '../lib/api'
import type { ApiEnvelope, PublicTableLink, PublicTableStatus } from '../types/api'

const statusLabel: Record<string, string> = { pending: 'Sent', preparing: 'Preparing', ready: 'Ready for service', served: 'Served' }
const publicUrl = (token: string) => `${window.location.origin}/table/${token}`
const safeFileName = (value: string) => value.replace(/[^a-z0-9_-]+/gi, '-').replace(/^-|-$/g, '').toLowerCase()
const escapeHtml = (value: string) => value.replace(/[&<>'"]/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' })[character] ?? character)

export function TableQrManagementPage() {
  const [links, setLinks] = useState<PublicTableLink[]>([])
  const [images, setImages] = useState<Record<number, string>>({})
  const [busy, setBusy] = useState<number | null>(null)
  const [rotateTarget, setRotateTarget] = useState<PublicTableLink | null>(null)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const load = useCallback(async () => { try { const response = await api.get<ApiEnvelope<PublicTableLink[]>>('/api/v1/table-public-links'); setLinks(response.data.data); setError('') } catch (requestError) { setError(errorMessage(requestError)) } }, [])

  useEffect(() => { const task = window.setTimeout(() => { void load() }, 0); return () => window.clearTimeout(task) }, [load])
  useEffect(() => {
    let active = true
    void Promise.all(links.filter((link) => link.token).map(async (link) => [link.table.id, await QRCode.toDataURL(publicUrl(link.token!), { width: 640, margin: 2, errorCorrectionLevel: 'H' })] as const)).then((entries) => { if (active) setImages(Object.fromEntries(entries)) })
    return () => { active = false }
  }, [links])

  async function generate(link: PublicTableLink) { setBusy(link.table.id); setError(''); try { await api.post(`/api/v1/tables/${link.table.id}/public-link`); setMessage(`${link.table.name} QR generated.`); await load() } catch (requestError) { setError(errorMessage(requestError)) } finally { setBusy(null) } }
  async function rotate() { if (!rotateTarget) return; setBusy(rotateTarget.table.id); setError(''); try { await api.post(`/api/v1/tables/${rotateTarget.table.id}/public-link/rotate`); setMessage(`${rotateTarget.table.name} QR rotated. The previous print no longer works.`); setRotateTarget(null); await load() } catch (requestError) { setError(errorMessage(requestError)) } finally { setBusy(null) } }
  async function toggle(link: PublicTableLink) { setBusy(link.table.id); setError(''); try { await api.patch(`/api/v1/tables/${link.table.id}/public-link`, { is_active: !link.is_active }); setMessage(`${link.table.name} QR ${link.is_active ? 'disabled' : 'enabled'}.`); await load() } catch (requestError) { setError(errorMessage(requestError)) } finally { setBusy(null) } }
  function download(link: PublicTableLink) { const image = images[link.table.id]; if (!image) return; const anchor = document.createElement('a'); anchor.href = image; anchor.download = `${safeFileName(link.table.outlet)}-${safeFileName(link.table.name)}-qr.png`; anchor.click() }
  function printCards(cards: PublicTableLink[]) {
    const printable = cards.filter((link) => link.token && images[link.table.id]); const popup = window.open('', '_blank', 'noopener,noreferrer')
    if (!popup) { setError('Printing was blocked by the browser. Allow pop-ups and try again.'); return }
    popup.document.write(`<!doctype html><html><head><title>Table QR cards</title><style>@page{margin:12mm}*{box-sizing:border-box}body{font-family:Arial,sans-serif;margin:0;color:#14202c}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12mm}.card{break-inside:avoid;text-align:center;border:2px solid #14202c;border-radius:14px;padding:18px}.card img{width:58mm;height:58mm}.card h1{margin:8px 0 2px;font-size:25px}.card p{margin:3px;color:#526075}.card strong{display:block;margin-top:10px;color:#117548}.card small{display:block;margin-top:8px}</style></head><body><main class="grid">${printable.map((link) => `<section class="card"><p>${escapeHtml(link.table.outlet)}</p><h1>${escapeHtml(link.table.name)}</h1><img src="${images[link.table.id]}" alt="QR code"><strong>Scan to view your live order</strong><small>View-only · No login required</small></section>`).join('')}</main><script>window.onload=()=>{window.print();window.onafterprint=()=>window.close()}</script></body></html>`); popup.document.close()
  }

  return <><section className="page-heading"><div><p className="eyebrow">OWNER SETTINGS</p><h1>Table QR cards</h1><p>Print one permanent, view-only status card for every physical table.</p></div><button className="button button-primary" disabled={!links.some((link) => link.is_active && link.token)} onClick={() => printCards(links.filter((link) => link.is_active))}><Printer size={17} />Print all active</button></section>
    <div className="qr-security-note"><ShieldCheck size={20} /><div><strong>Safe permanent links</strong><p>The QR follows the table, not a guest. It shows only the table's current active order. Rotate it only if a printed card or URL is exposed.</p></div></div>
    {error && <p className="form-error form-notice">{error}</p>}{message && <p className="form-success form-notice"><CheckCircle2 size={16} />{message}</p>}
    <section className="qr-management-grid">{links.map((link) => <article className={`qr-management-card ${!link.is_active ? 'disabled' : ''}`} key={link.table.id}><header><div><small>{link.table.outlet}</small><h2>{link.table.name}</h2><span>{link.table.code}</span></div><span className={`status-badge ${link.is_active ? 'status-green' : 'status-red'}`}>{link.token ? (link.is_active ? 'Active' : 'Disabled') : 'Not generated'}</span></header><div className="qr-preview">{images[link.table.id] ? <img src={images[link.table.id]} alt={`QR code for ${link.table.name}`} /> : <QrCode size={90} />}</div>{link.token ? <><a className="qr-link-preview" href={publicUrl(link.token)} target="_blank" rel="noreferrer" title="Open guest table status">{publicUrl(link.token)}</a><div className="qr-actions"><button className="button button-primary open-guest-view" onClick={() => window.open(publicUrl(link.token!), '_blank', 'noopener,noreferrer')}><ExternalLink size={15} />Open guest view</button><button className="button button-secondary" onClick={() => download(link)} disabled={!images[link.table.id]}><Download size={15} />Download</button><button className="button button-secondary" onClick={() => printCards([link])} disabled={!images[link.table.id]}><Printer size={15} />Print</button><button className="button button-secondary" onClick={() => setRotateTarget(link)} disabled={busy === link.table.id}><RefreshCw size={15} />Rotate</button><button className={`button ${link.is_active ? 'button-danger-soft' : 'button-primary'}`} onClick={() => void toggle(link)} disabled={busy === link.table.id}>{link.is_active ? 'Disable' : 'Enable'}</button></div></> : <button className="button button-primary button-full" disabled={busy === link.table.id} onClick={() => void generate(link)}>Generate QR</button>}</article>)}</section>
    {!links.length && !error && <div className="qr-empty"><QrCode size={42} /><h2>No dining tables yet</h2><p>Create tables first; their secure QR links will be generated automatically.</p></div>}
    <ConfirmDialog open={Boolean(rotateTarget)} title="Rotate this table QR?" text={`The QR already printed for ${rotateTarget?.table.name ?? 'this table'} will immediately stop working. You must print and replace it with the new QR.`} confirmLabel="Rotate QR" onClose={() => setRotateTarget(null)} onConfirm={() => void rotate()} /></>
}

export function CustomerTableStatusPage() {
  const { token = '' } = useParams()
  const [status, setStatus] = useState<PublicTableStatus | null>(null)
  const statusRef = useRef<PublicTableStatus | null>(null)
  const [loading, setLoading] = useState(true)
  const [unavailable, setUnavailable] = useState(false)
  const [offline, setOffline] = useState(!navigator.onLine)
  const [calling, setCalling] = useState(false)
  const [callError, setCallError] = useState('')
  const etag = useRef<string | null>(null)
  const refresh = useCallback(async () => {
    if (!navigator.onLine) { setOffline(true); return }
    try {
      const response = await api.get<ApiEnvelope<PublicTableStatus>>(`/api/v1/public/tables/${encodeURIComponent(token)}/status`, { headers: etag.current ? { 'If-None-Match': etag.current } : {}, validateStatus: (code) => code === 200 || code === 304 })
      if (response.status === 200) { setStatus(response.data.data); statusRef.current = response.data.data; etag.current = response.headers.etag ?? null }
      setUnavailable(false); setOffline(false)
    } catch (requestError) {
      if (axios.isAxiosError(requestError) && requestError.response?.status === 404) setUnavailable(true)
      else if (!statusRef.current) setOffline(true)
    } finally { setLoading(false) }
  }, [token])
  useEffect(() => {
    const firstLoad = window.setTimeout(() => { void refresh() }, 0)
    const timer = window.setInterval(() => { if (!document.hidden) void refresh() }, 10000)
    const online = () => { setOffline(false); void refresh() }
    const offlineHandler = () => setOffline(true)
    const visible = () => { if (!document.hidden) void refresh() }
    window.addEventListener('online', online)
    window.addEventListener('offline', offlineHandler)
    document.addEventListener('visibilitychange', visible)
    return () => { window.clearTimeout(firstLoad); window.clearInterval(timer); window.removeEventListener('online', online); window.removeEventListener('offline', offlineHandler); document.removeEventListener('visibilitychange', visible) }
  }, [refresh])
  const currency = status?.currency ?? 'INR'
  const money = (value: string) => new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(Number(value))
  const hasReadyItem = Boolean(status?.active_order?.items.some((item) => item.status === 'ready'))
  const callWaiter = async () => {
    if (calling || status?.waiter_called) return
    setCalling(true); setCallError('')
    try {
      await api.post(`/api/v1/public/tables/${encodeURIComponent(token)}/call-waiter`)
      setStatus((current) => current ? { ...current, waiter_called: true } : current)
    } catch (requestError) { setCallError(errorMessage(requestError)) }
    finally { setCalling(false) }
  }
  const callButton = <div className="customer-call-waiter">
    {hasReadyItem && !status?.waiter_called && <p className="customer-ready-hint">Your order is ready. If a waiter has not reached you, tap Call waiter.</p>}
    <button type="button" className="button button-primary customer-call-button" disabled={calling || Boolean(status?.waiter_called) || offline} onClick={() => void callWaiter()}>
      <BellRing size={16} />{status?.waiter_called ? 'Waiter notified' : calling ? 'Calling…' : 'Call waiter'}
    </button>
    {callError && <p className="form-error">{callError}</p>}
  </div>
  if (loading) return <main className="customer-status-page"><div className="customer-status-card customer-loading">Loading your table…</div></main>
  if (unavailable) return <main className="customer-status-page"><div className="customer-status-card customer-empty"><QrCode size={46} /><h1>This QR is not active</h1><p>Please ask a staff member for assistance.</p></div></main>
  return <main className="customer-status-page"><section className="customer-status-card">
    <header className="customer-brand"><div><p className="eyebrow">LIVE TABLE STATUS</p><h1>{status?.hotel_name ?? 'Restaurant'}</h1><p>{status?.outlet_name}</p></div><div className="customer-table-name"><small>Your table</small><strong>{status?.table_name}</strong></div></header>
    {offline && <div className="customer-offline"><WifiOff size={16} />Offline — showing the last received status</div>}
    {!status?.active_order ? <div className="customer-empty"><CheckCircle2 size={48} /><h2>No active order</h2><p>This page will update automatically when an order is opened for this table.</p>{callButton}</div> : <>
      {status.active_order.bill_requested && <div className="customer-bill-requested"><CheckCircle2 size={18} />Bill requested. The counter is preparing it.</div>}
      <section className="customer-items"><h2>Your order</h2>{status.active_order.items.map((item, index) => <div className={`customer-item ${item.status === 'ready' ? 'is-ready' : ''}`} key={`${item.name}-${index}`}><b>{Number(item.quantity)} ×</b><span><strong>{item.name}</strong><small className={`customer-item-status ${item.status}`}>{statusLabel[item.status] ?? item.status}</small>{item.status === 'ready' && <em className="customer-ready-call-note">Order is ready. If nobody has come to you, call waiter.</em>}</span>{item.status === 'ready' && <button type="button" className="button button-secondary customer-item-call" disabled={calling || Boolean(status.waiter_called) || offline} onClick={() => void callWaiter()}><BellRing size={14} />{status.waiter_called ? 'Waiter notified' : 'Call waiter'}</button>}</div>)}</section>
      <section className="customer-totals"><div><span>Subtotal</span><strong>{money(status.active_order.subtotal)}</strong></div><div><span>Tax</span><strong>{money(status.active_order.tax)}</strong></div><div className="customer-grand-total"><span>Current total</span><strong>{money(status.active_order.total)}</strong></div></section>
      {callButton}
    </>}
    <footer>View only · Refreshes automatically every 10 seconds{status?.last_updated_at ? <small>Last updated {new Date(status.last_updated_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</small> : null}</footer>
  </section></main>
}
