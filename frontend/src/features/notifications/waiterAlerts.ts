import { api } from '../../lib/api'
import type { ApiEnvelope } from '../../types/api'

function urlBase64ToUint8Array(value: string) {
  const padding = '='.repeat((4 - (value.length % 4)) % 4)
  const raw = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'))
  return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)))
}

export async function registerWaiterServiceWorker() {
  if (!('serviceWorker' in navigator)) return null
  try {
    return await navigator.serviceWorker.register('/sw.js', { scope: '/' })
  } catch {
    return null
  }
}

export async function subscribeWaiterPush(): Promise<'subscribed' | 'unavailable' | 'failed'> {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return 'unavailable'
  try {
    const config = (await api.get<ApiEnvelope<{ enabled: boolean; public_key: string | null }>>('/api/v1/push/config')).data.data
    if (!config.enabled || !config.public_key || window.Notification?.permission !== 'granted') return config.enabled && window.Notification?.permission === 'granted' ? 'failed' : 'unavailable'
    const registration = await registerWaiterServiceWorker() ?? await navigator.serviceWorker.ready
    const existing = await registration.pushManager.getSubscription()
    const subscription = existing ?? await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(config.public_key),
    })
    const json = subscription.toJSON()
    if (!json.endpoint || !json.keys?.p256dh || !json.keys?.auth) return 'failed'
    await api.post('/api/v1/push/subscriptions', {
      endpoint: json.endpoint,
      keys: { p256dh: json.keys.p256dh, auth: json.keys.auth },
      content_encoding: 'aes128gcm',
    })
    return 'subscribed'
  } catch {
    return 'failed'
  }
}
