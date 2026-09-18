import { useEffect, useId, useRef, useState } from 'react'
import type { FormEvent, ReactNode } from 'react'

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

function focusables(root: HTMLElement | null) {
  return root ? Array.from(root.querySelectorAll<HTMLElement>(FOCUSABLE)).filter((node) => !node.hasAttribute('disabled') && node.getAttribute('aria-hidden') !== 'true') : []
}

export function Toast({ message, tone = 'success', onDismiss }: { message: string; tone?: 'success' | 'error'; onDismiss?: () => void }) {
  if (!message) return null
  return <div className={`app-toast ${tone}`} role="status"><span>{message}</span>{onDismiss && <button type="button" onClick={onDismiss} aria-label="Dismiss notification">×</button>}</div>
}

export function Modal({ open, title, description, children, onClose }: { open: boolean; title: string; description?: string; children: ReactNode; onClose: () => void }) {
  const titleId = useId()
  const descriptionId = useId()
  const dialogRef = useRef<HTMLElement>(null)
  const lastFocus = useRef<HTMLElement | null>(null)

  useEffect(() => {
    if (!open) return
    lastFocus.current = document.activeElement instanceof HTMLElement ? document.activeElement : null
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    const frame = window.requestAnimationFrame(() => {
      const nodes = focusables(dialogRef.current)
      const preferred = nodes.find((node) => node.tagName === 'INPUT' || node.tagName === 'TEXTAREA' || node.tagName === 'SELECT') ?? nodes.find((node) => node.getAttribute('aria-label') !== 'Close') ?? nodes[0]
      preferred?.focus()
    })
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault()
        onClose()
        return
      }
      if (event.key !== 'Tab') return
      const nodes = focusables(dialogRef.current)
      if (!nodes.length) return
      const first = nodes[0]
      const last = nodes[nodes.length - 1]
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }
    document.addEventListener('keydown', onKey)
    return () => {
      window.cancelAnimationFrame(frame)
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = previousOverflow
      lastFocus.current?.focus()
    }
  }, [open, onClose])

  if (!open) return null
  return <div className="modal-backdrop" role="presentation" onMouseDown={onClose}>
    <section ref={dialogRef} className="app-modal" role="dialog" aria-modal="true" aria-labelledby={titleId} aria-describedby={description ? descriptionId : undefined} onMouseDown={(event) => event.stopPropagation()}>
      <header><h2 id={titleId}>{title}</h2><button type="button" onClick={onClose} aria-label="Close">×</button></header>
      {description && <p id={descriptionId} className="modal-intro">{description}</p>}
      {children}
    </section>
  </div>
}

export function ConfirmDialog({ open, title, text, confirmLabel = 'Confirm', onConfirm, onClose }: { open: boolean; title: string; text: string; confirmLabel?: string; onConfirm: () => void; onClose: () => void }) {
  return <Modal open={open} title={title} description={text} onClose={onClose}><div className="form-action-row"><button type="button" className="button button-secondary" onClick={onClose}>Cancel</button><button type="button" className="button button-primary" onClick={onConfirm}>{confirmLabel}</button></div></Modal>
}

export function PromptDialog({
  open, title, description, label, defaultValue = '', type = 'text', confirmLabel = 'Continue', minLength = 1, min, step, required = true, busy = false, onConfirm, onClose,
}: {
  open: boolean
  title: string
  description?: string
  label: string
  defaultValue?: string
  type?: 'text' | 'number' | 'textarea'
  confirmLabel?: string
  minLength?: number
  min?: number
  step?: number
  required?: boolean
  busy?: boolean
  onConfirm: (value: string) => void
  onClose: () => void
}) {
  const [value, setValue] = useState(defaultValue)
  useEffect(() => { if (open) setValue(defaultValue) }, [open, defaultValue])
  const valid = !required || (type === 'number' ? Number(value) >= (min ?? 1) : value.trim().length >= minLength)
  function submit(event: FormEvent) {
    event.preventDefault()
    if (!valid || busy) return
    onConfirm(type === 'number' ? value : value.trim())
  }
  return <Modal open={open} title={title} description={description} onClose={() => !busy && onClose()}>
    <form className="prompt-form" onSubmit={submit}>
      <label className="field"><span>{label}</span>
        {type === 'textarea'
          ? <textarea rows={4} maxLength={1000} value={value} onChange={(event) => setValue(event.target.value)} required={required} />
          : <input type={type} value={value} min={min} step={step} required={required} onChange={(event) => setValue(event.target.value)} />}
      </label>
      <div className="form-action-row">
        <button type="button" className="button button-secondary button-touch" disabled={busy} onClick={onClose}>Cancel</button>
        <button type="submit" className="button button-primary button-touch" disabled={!valid || busy}>{busy ? 'Saving…' : confirmLabel}</button>
      </div>
    </form>
  </Modal>
}

export function DataTable({ children }: { children: ReactNode }) { return <div className="data-table">{children}</div> }
