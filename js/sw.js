self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(clients.claim()));

self.addEventListener('notificationclick', e => {
    e.notification.close();
    e.waitUntil(
        clients.matchAll({type: 'window', includeUncontrolled: true}).then(list => {
            const open = list.find(c => 'focus' in c);
            if (open) return open.focus();
            return clients.openWindow(self.registration.scope);
        })
    );
});
