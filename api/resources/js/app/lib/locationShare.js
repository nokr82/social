/**
 * 내 위치 공유 (보내는 쪽).
 * 로그인 후 App.vue가 start()를 부르면, 공유 중인 친구가 있을 때만 위치를 지켜보며 서버로 보낸다.
 *
 * 보내는 규칙
 * - 50m 이상 움직였으면 바로 (단, 10초에 1번까지)
 * - 제자리여도 30초마다 1번 ('방금' 표시가 유지되게)
 * - 공유 중인 친구가 없으면(끔·만료) 위치 감시 자체를 멈춘다 → 배터리·권한 사용 최소화
 * 제약: 웹앱이라 화면이 꺼지거나 다른 앱으로 가면 브라우저가 멈춘다 (친구 지도엔 'N분 전'으로 보임)
 */
import { reactive } from 'vue';
import { del, get, post, put } from './api';
import { showToast } from './toast';

const SEND_EVERY_MS = 30_000;
const MIN_GAP_MS = 10_000;
const MOVE_M = 50;

/** shares: [{user, until}] 내가 공유 중인 친구, denied: 위치 권한 거부됨 */
export const locShare = reactive({ shares: [], denied: false });

let watchId = null;
let ticker = null;
let expiryTimer = null;
let latest = null;  // 가장 최근 GPS 값
let lastSent = null; // {lat, lng, t}

export async function startLocationShare() {
    await refreshShares();
}

export function stopLocationShare() {
    locShare.shares = [];
    clearTimeout(expiryTimer);
    stopWatch();
}

export async function refreshShares() {
    try {
        setShares((await get('/location/shares')).data);
    } catch {
        /* 네트워크 오류: 다음 기회에 */
    }
}

export async function shareWith(userId, duration) {
    setShares((await put(`/location/shares/${userId}`, { duration })).data);
}

export async function stopSharingWith(userId) {
    setShares((await del(`/location/shares/${userId}`)).data);
}

export async function stopAllSharing() {
    await del('/location/shares');
    setShares([]);
}

function setShares(list) {
    locShare.shares = list;
    scheduleExpiryCheck();
    if (list.length && navigator.geolocation) startWatch();
    else stopWatch();
}

/** 가장 먼저 끝나는 공유 시각에 목록을 다시 받아 '공유 중' 표시를 정리 */
function scheduleExpiryCheck() {
    clearTimeout(expiryTimer);
    const ends = locShare.shares.filter((s) => s.until).map((s) => new Date(s.until).getTime());
    if (!ends.length) return;
    const ms = Math.min(Math.max(Math.min(...ends) - Date.now() + 1000, 1000), 2 ** 31 - 1);
    expiryTimer = setTimeout(refreshShares, ms);
}

function startWatch() {
    if (watchId !== null) return;
    watchId = navigator.geolocation.watchPosition(onPosition, onError, {
        enableHighAccuracy: true, maximumAge: 10_000, timeout: 30_000,
    });
    ticker = setInterval(() => {
        if (latest && !document.hidden && (!lastSent || Date.now() - lastSent.t >= SEND_EVERY_MS)) send(latest);
    }, 5_000);
    // 앱으로 돌아오면 바로 한 번 (멈춰 있던 동안 위치가 바뀌었을 수 있음)
    document.addEventListener('visibilitychange', onVisible);
}

function stopWatch() {
    if (watchId !== null) navigator.geolocation.clearWatch(watchId);
    watchId = null;
    clearInterval(ticker);
    document.removeEventListener('visibilitychange', onVisible);
    latest = null;
    lastSent = null;
}

function onVisible() {
    if (!document.hidden && latest) send(latest);
}

function onPosition(pos) {
    locShare.denied = false;
    latest = { lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy };
    const now = Date.now();
    if (!lastSent) return send(latest);
    const gap = now - lastSent.t;
    if (gap >= SEND_EVERY_MS || (gap >= MIN_GAP_MS && meters(lastSent, latest) >= MOVE_M)) send(latest);
}

function onError(err) {
    if (err.code === 1) { // PERMISSION_DENIED
        locShare.denied = true;
        stopWatch();
        showToast('위치 권한이 꺼져 있어 위치 공유가 멈췄어요. 브라우저 설정에서 허용해 주세요', 4000);
    }
}

async function send(p) {
    lastSent = { ...p, t: Date.now() };
    try {
        const res = await post('/location', p);
        // 서버 기준으로 보여줄 친구가 없으면(만료·차단) 목록을 다시 받아 감시를 멈춘다
        if (!res.sharing) refreshShares();
    } catch {
        /* 일시 오류·요청 제한은 다음 주기에 다시 */
    }
}

/** 두 좌표 사이 거리(m) — 하버사인 공식 */
export function meters(a, b) {
    const R = 6371000;
    const rad = (d) => (d * Math.PI) / 180;
    const dLat = rad(b.lat - a.lat);
    const dLng = rad(b.lng - a.lng);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(h));
}

/** '320m' / '1.4km' */
export function distanceText(m) {
    return m < 1000 ? `${Math.round(m / 10) * 10}m` : `${(m / 1000).toFixed(1)}km`;
}

/** 공유 남은 시간: '끌 때까지' / '42분 남음' / '3시간 남음' */
export function remainText(until) {
    if (!until) return '끌 때까지';
    const m = Math.max(0, Math.round((new Date(until).getTime() - Date.now()) / 60000));
    if (m < 60) return `${m}분 남음`;
    return `${Math.floor(m / 60)}시간${m % 60 ? ` ${m % 60}분` : ''} 남음`;
}
