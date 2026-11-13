/**
 * 서비스 워커: 웹 푸시 수신 전용 (오프라인 캐시는 하지 않음 → 배포 후 옛 화면이 남는 문제 없음)
 *
 * 서버(WebPushMessage)가 보내는 형식: { title, body, icon, badge, tag, data: { url } }
 */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let msg = {};
    try {
        msg = event.data ? event.data.json() : {};
    } catch {
        msg = { title: '소셜링', body: event.data?.text() ?? '' };
    }
    const url = msg.data?.url ?? '/';

    event.waitUntil((async () => {
        // 지금 그 화면(예: 같은 대화방)을 보고 있으면 폰 알림은 생략 — 화면에 이미 실시간으로 뜸
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const watching = windows.some((c) => c.focused && new URL(c.url).pathname === url);
        if (watching) return;

        await self.registration.showNotification(msg.title ?? '소셜링', {
            body: msg.body ?? '',
            icon: msg.icon ?? '/icons/app-icon-192.png',
            badge: msg.badge ?? '/icons/badge-72.png',
            tag: msg.tag,          // 같은 tag는 덮어씀 (대화방당 알림 1개)
            renotify: !!msg.tag,   // 덮어써도 다시 진동/소리
            data: { url },
        });
    })());
});

// 알림을 누르면: 열린 앱 창이 있으면 그 창을 해당 화면으로, 없으면 새로 연다
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = event.notification.data?.url ?? '/';

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const win = windows.find((c) => new URL(c.url).origin === self.location.origin);
        if (win) {
            await win.focus();
            // 앱(SPA)에게 화면 이동을 부탁 → 새로고침 없이 이동
            win.postMessage({ type: 'navigate', url });
            return;
        }
        await self.clients.openWindow(url);
    })());
});
