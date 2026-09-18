self.addEventListener('push', (event) => {
  let payload = { title: 'DineSetu', body: 'Floor alert', url: '/app/orders', tag: 'aswad-floor' }
  try {
    payload = { ...payload, ...(event.data ? event.data.json() : {}) }
  } catch (error) {
    /* keep defaults */
  }
  event.waitUntil(self.registration.showNotification(payload.title, {
    body: payload.body,
    icon: '/icon-192.png',
    badge: '/icon-192.png',
    tag: payload.tag,
    data: { url: payload.url || '/app/orders' },
    vibrate: [400, 120, 400, 120, 700],
    requireInteraction: true,
    silent: false,
  }))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = event.notification.data?.url || '/app/orders'
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
    const existing = windows.find((client) => 'focus' in client && client.url.includes('/app'))
    if (existing) {
      existing.postMessage({ type: 'aswad-open', url })
      return existing.focus()
    }
    return self.clients.openWindow(url)
  }))
})
