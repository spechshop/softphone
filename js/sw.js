self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(clients.claim()));

self.addEventListener('push', event => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (_) {
        data = {};
    }

    const title = data.title || 'Nova mensagem';
    const options = {
        body: data.body || 'Voce recebeu uma nova mensagem',
        icon: data.icon || '/img/pyramid.png',
        badge: data.badge || '/img/pyramid.png',
        tag: data.tag || 'spech-message',
        renotify: true,
        data: {
            url: data.url || '/',
            from: data.from || null,
            messageId: data.messageId || null,
        },
    };

    event.waitUntil(
        clients.matchAll({type: 'window', includeUncontrolled: true}).then(list => {
            // Suprime se o app esta visivel (WebSocket ja entrega em tempo real)
            if (list.some(c => c.visibilityState === 'visible')) return;
            return self.registration.showNotification(title, options);
        })
    );
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const url = event.notification.data?.url || '/';

    event.waitUntil(
        clients.matchAll({type: 'window', includeUncontrolled: true}).then(list => {
            for (const client of list) {
                if ('focus' in client) {
                    client.focus();
                    if ('navigate' in client) return client.navigate(url);
                    return;
                }
            }
            return clients.openWindow(url);
        })
    );
});
