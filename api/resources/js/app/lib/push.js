/**
 * 웹 푸시 (폰/PC 알림).
 * 흐름: 서비스 워커 등록 → 사용자가 '켜기'를 누르면 권한 요청 → 브라우저 구독 → 서버에 기기 등록
 *
 * 주의
 * - 권한 요청은 반드시 버튼 클릭 안에서 (브라우저가 자동 팝업을 막음, 거절하면 다시 묻기 어려움)
 * - HTTPS(또는 localhost)에서만 동작
 * - iPhone은 Safari '공유 → 홈 화면에 추가'로 설치한 앱에서만 가능 (iOS 16.4+)
 */
import { del, get, post } from './api';

export const isIOS = () => /iP(hone|ad|od)/.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1); // iPadOS
export const isStandalone = () => window.matchMedia?.('(display-mode: standalone)').matches || navigator.standalone === true;

/** 'ok' | 'insecure'(HTTP) | 'ios-install'(홈 화면 추가 필요) | 'unsupported' */
export function pushSupport() {
    if (!window.isSecureContext) return 'insecure';
    if (isIOS() && !isStandalone()) return 'ios-install';
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return 'unsupported';
    return 'ok';
}

export function registerServiceWorker() {
    if (!('serviceWorker' in navigator) || !window.isSecureContext) return null;
    return navigator.serviceWorker.register('/sw.js').catch(() => null);
}

async function currentSubscription() {
    const reg = await navigator.serviceWorker.ready;
    return reg.pushManager.getSubscription();
}

/** 이 기기에서 푸시가 켜져 있는지 */
export async function isPushOn() {
    if (pushSupport() !== 'ok' || Notification.permission !== 'granted') return false;
    return !!(await currentSubscription());
}

/** base64url 공개키 → 브라우저가 요구하는 바이트 배열 */
function keyToBytes(base64url) {
    const pad = '='.repeat((4 - (base64url.length % 4)) % 4);
    const raw = atob((base64url + pad).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
}

/**
 * 푸시 켜기 (버튼 클릭에서 호출).
 * @returns {'on'|'denied'|'no-key'|string} 실패 사유는 pushSupport() 값
 */
export async function enablePush() {
    const support = pushSupport();
    if (support !== 'ok') return support;

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return 'denied';

    const { public_key: key } = await get('/meta/push');
    if (!key) return 'no-key'; // 서버에 VAPID 키가 아직 없음

    const reg = await navigator.serviceWorker.ready;
    const sub = (await reg.pushManager.getSubscription())
        ?? await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyToBytes(key) });

    const json = sub.toJSON();
    await post('/push/subscriptions', {
        endpoint: json.endpoint,
        keys: json.keys,
        content_encoding: (PushManager.supportedContentEncodings ?? ['aes128gcm']).includes('aes128gcm') ? 'aes128gcm' : 'aesgcm',
    });
    return 'on';
}

/** 이 기기 푸시 끄기 (다른 기기는 그대로) */
export async function disablePush() {
    const sub = await currentSubscription();
    if (!sub) return;
    await del(`/push/subscriptions?endpoint=${encodeURIComponent(sub.endpoint)}`).catch(() => {});
    await sub.unsubscribe();
}
