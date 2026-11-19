<script setup>
/**
 * 번개 '모이는 중' 지도: 목적지 핀 + 위치 공유를 켠 참여자.
 * - 참여 확정자만 (서버가 확인). 내 공유는 기본 꺼짐 → 아래 버튼으로 켜고 끔. 기간 없음(채팅방 닫히면 자동으로 끝)
 * - 실시간: 이 화면을 연 동안만 location.{내 id} 채널의 '.meetup.location.*' 이벤트를 받음 (친구 지도 이벤트와 이름이 달라 안 섞임)
 * - 1분마다 다시 받아 새로 켠 사람·끈 사람을 반영 (위치 확인 기록·통보도 서버가 이때 처리)
 * - 내 점('나')은 이 기기의 현재 위치로 그림 (공유를 안 켜도 나는 내 위치를 볼 수 있음, 서버로 안 보냄)
 * - 지도 키가 없거나 못 불러오면 목록만
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import { useRouter } from 'vue-router';
import AvatarFace from '../components/AvatarFace.vue';
import { ApiError, get } from '../lib/api';
import { auth } from '../lib/auth';
import { colorHex } from '../lib/avatar';
import { connectEcho } from '../lib/echo';
import { loadKakaoMaps } from '../lib/kakaoMap';
import { getLocation } from '../lib/location';
import { distanceText, meters, startMeetupShare, stopMeetupShare } from '../lib/locationShare';
import { ago } from '../lib/time';
import { showToast } from '../lib/toast';

const props = defineProps({ id: { type: String, required: true } });
const meetupId = Number(props.id);
const router = useRouter();

const board = ref(null);          // {meetup, destination, place_detail, me_sharing, members[]}
const myPos = ref(null);
const mapEl = ref(null);
const map = shallowRef(null);
const noMap = ref(false);
const busy = ref(false);
const now = ref(Date.now());

const overlays = new Map();       // user id → CustomOverlay
let destOverlay = null;
let meOverlay = null;
let channel = null;
let refreshTimer = null;
let clockTimer = null;
let fitted = false;

const others = computed(() => (board.value?.members ?? []).filter((m) => !m.me));
const located = computed(() => others.value.filter((m) => m.position));
const sharingCount = computed(() => (board.value?.members ?? []).filter((m) => m.sharing).length);

async function load() {
    try {
        board.value = (await get(`/meetups/${meetupId}/locations`)).data;
        drawAll();
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '지도를 불러오지 못했어요');
        if (e instanceof ApiError && e.status === 403) router.replace({ name: 'meetup', params: { id: meetupId } });
    }
}

/** 마커 DOM은 직접 만든다 (닉네임을 HTML 문자열로 넣지 않음 → 스크립트 삽입 방지) */
function pinElement(label, color, cls = '') {
    const el = document.createElement('div');
    el.className = `mm-pin ${cls}`;
    const dot = document.createElement('span');
    dot.className = 'mm-dot';
    dot.style.background = color;
    const name = document.createElement('span');
    name.className = 'mm-name';
    name.textContent = label;
    el.append(dot, name);
    return el;
}

function destElement(label) {
    const el = document.createElement('div');
    el.className = 'mm-dest';
    const name = document.createElement('span');
    name.className = 'mm-dest-name';
    name.textContent = `🚩 ${label}`;
    const stick = document.createElement('span');
    stick.className = 'mm-dest-stick';
    el.append(name, stick);
    return el;
}

function drawAll() {
    const kakao = window.kakao;
    if (!map.value || !kakao || !board.value) return;
    const dest = board.value.destination;

    if (dest && !destOverlay) {
        destOverlay = new kakao.maps.CustomOverlay({ position: new kakao.maps.LatLng(dest.lat, dest.lng), content: destElement(dest.name), yAnchor: 1, zIndex: 3 });
        destOverlay.setMap(map.value);
    }

    const alive = new Set(located.value.map((m) => m.user.id));
    for (const [uid, o] of overlays) if (!alive.has(uid)) { o.setMap(null); overlays.delete(uid); }
    for (const m of located.value) drawMember(m);

    if (myPos.value) {
        const pos = new kakao.maps.LatLng(myPos.value.lat, myPos.value.lng);
        if (!meOverlay) meOverlay = new kakao.maps.CustomOverlay({ position: pos, content: pinElement('나', '#6C5CE7', 'mm-me'), yAnchor: 1, zIndex: 2 });
        meOverlay.setPosition(pos);
        meOverlay.setMap(map.value);
    }

    // 처음 한 번: 목적지·참여자·나가 다 보이게 (이후엔 사용자가 움직인 화면 유지)
    if (!fitted) {
        const pts = [
            ...(dest ? [dest] : []),
            ...located.value.map((m) => m.position),
            ...(myPos.value ? [myPos.value] : []),
        ];
        if (pts.length > 1) {
            const b = new kakao.maps.LatLngBounds();
            pts.forEach((p) => b.extend(new kakao.maps.LatLng(p.lat, p.lng)));
            map.value.setBounds(b, 60, 40, 260, 40);
            fitted = true;
        } else if (pts.length === 1) {
            map.value.setCenter(new kakao.maps.LatLng(pts[0].lat, pts[0].lng));
            fitted = true;
        }
    }
}

function drawMember(m) {
    const kakao = window.kakao;
    if (!map.value || !kakao || !m.position) return;
    const pos = new kakao.maps.LatLng(m.position.lat, m.position.lng);
    let o = overlays.get(m.user.id);
    if (!o) {
        o = new kakao.maps.CustomOverlay({ position: pos, content: pinElement(m.user.nickname, colorHex(m.user.avatar?.color)), yAnchor: 1, zIndex: 2 });
        o.setMap(map.value);
        overlays.set(m.user.id, o);
    }
    o.setPosition(pos);
}

/** 실시간: 이 번개 것만. 목록에 없는 사람이면(새로 참여) 다시 받음 */
function onUpdated(p) {
    if (p.meetup_id !== meetupId || !board.value) return;
    const m = board.value.members.find((x) => x.user.id === p.user_id);
    if (!m) return load();
    m.sharing = true;
    m.position = p;
    const d = board.value.destination;
    m.distance_m = d ? Math.round(meters(p, d)) : null;
    drawMember(m);
}

function onStopped(p) {
    if (p.meetup_id !== meetupId || !board.value) return;
    const m = board.value.members.find((x) => x.user.id === p.user_id);
    if (m) { m.sharing = false; m.position = null; m.distance_m = null; }
    overlays.get(p.user_id)?.setMap(null);
    overlays.delete(p.user_id);
}

async function toggleShare() {
    if (busy.value) return;
    busy.value = true;
    try {
        if (board.value.me_sharing) {
            board.value = await stopMeetupShare(meetupId);
            showToast('위치 공유를 껐어요');
        } else {
            const loc = await getLocation({ fresh: true });
            if (loc.fallback) {
                showToast('위치 권한을 켜야 공유할 수 있어요. 브라우저·휴대폰 설정에서 허용해 주세요', 4000);
                return;
            }
            myPos.value = { lat: loc.lat, lng: loc.lng };
            board.value = await startMeetupShare(meetupId);
            showToast('이 번개 참여자에게 내 위치를 보여주고 있어요');
        }
        drawAll();
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '잠시 후 다시 시도해 주세요');
    } finally {
        busy.value = false;
    }
}

function focus(m) {
    if (m.me) {
        if (myPos.value && map.value) map.value.panTo(new window.kakao.maps.LatLng(myPos.value.lat, myPos.value.lng));
        return;
    }
    if (!m.sharing) return showToast(`${m.user.nickname}님은 위치를 공유하지 않았어요`);
    if (!m.position) return showToast(`${m.user.nickname}님이 앱을 열면 위치가 보여요`);
    if (map.value) map.value.panTo(new window.kakao.maps.LatLng(m.position.lat, m.position.lng));
}

function focusDest() {
    const d = board.value?.destination;
    if (d && map.value) map.value.panTo(new window.kakao.maps.LatLng(d.lat, d.lng));
}

function statusText(m) {
    void now.value; // 시간이 흐르면 'N분 전' 다시 계산
    if (m.arrived) return '도착했어요';
    if (m.me) {
        if (!board.value.me_sharing) return '내 위치는 아직 비공개';
        const d = board.value.destination;
        return myPos.value && d ? `목적지까지 ${distanceText(meters(myPos.value, d))} · 공유 중` : '공유 중';
    }
    if (!m.sharing) return '위치 비공개';
    if (!m.position) return '앱을 열면 위치가 보여요';
    return [m.distance_m !== null ? `목적지까지 ${distanceText(m.distance_m)}` : null, ago(m.position.at)].filter(Boolean).join(' · ');
}

onMounted(async () => {
    if (!auth.me) auth.me = (await get('/me')).data;

    channel = `location.${auth.me.id}`;
    connectEcho().private(channel)
        .listen('.meetup.location.updated', onUpdated)
        .listen('.meetup.location.stopped', onStopped);

    const [kakao, loc] = await Promise.all([loadKakaoMaps(), getLocation(), load()]);
    if (!loc.fallback) myPos.value = { lat: loc.lat, lng: loc.lng };

    if (kakao && mapEl.value && board.value) {
        await nextTick();
        const c = board.value.destination ?? myPos.value ?? { lat: 37.5665, lng: 126.978 };
        map.value = new kakao.maps.Map(mapEl.value, { center: new kakao.maps.LatLng(c.lat, c.lng), level: 4 });
        drawAll();
    } else {
        noMap.value = true;
    }

    refreshTimer = setInterval(load, 60_000);
    clockTimer = setInterval(() => (now.value = Date.now()), 30_000);
});

onBeforeUnmount(() => {
    if (channel) window.Echo?.leave(channel);
    clearInterval(refreshTimer);
    clearInterval(clockTimer);
    overlays.forEach((o) => o.setMap(null));
    destOverlay?.setMap(null);
    meOverlay?.setMap(null);
});
</script>

<template>
    <div class="mm">
        <div class="head">
            <button class="icon" aria-label="뒤로가기" @click="router.back()">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
            </button>
            <div class="title-col">
                <h1>모이는 중</h1>
                <small>{{ board?.meetup.title }} · 공유 {{ sharingCount }}명</small>
            </div>
            <button v-if="board?.destination" class="dest-btn" @click="focusDest">🚩 목적지</button>
        </div>

        <div v-show="!noMap" ref="mapEl" class="map" />
        <p v-if="noMap" class="nomap">지도를 불러오지 못해 목록으로 보여드려요.</p>

        <div v-if="board" class="panel scroll" :class="{ full: noMap }">
            <p class="dest">
                <b>🚩 {{ board.destination?.name ?? board.place_detail ?? '장소 미정' }}</b>
                <small v-if="board.destination">{{ board.destination.address }}</small>
            </p>

            <!-- 내 공유: 켜기 전엔 눈에 띄게, 켠 뒤엔 끄기 쉽게 -->
            <template v-if="board.meetup.room_open">
                <button v-if="!board.me_sharing" class="share-on" :disabled="busy" @click="toggleShare">
                    📍 내 위치 공유하기
                    <small>이 번개 참여자에게만 보여요 · 언제든 끌 수 있어요</small>
                </button>
                <div v-else class="share-state">
                    <span>📍 내 위치를 공유하고 있어요</span>
                    <button class="share-off" :disabled="busy" @click="toggleShare">끄기</button>
                </div>
            </template>
            <p v-else class="closed">끝난 번개라 위치 공유가 꺼졌어요</p>

            <button v-for="m in board.members" :key="m.user.id" class="row" @click="focus(m)">
                <AvatarFace :parts="m.user.avatar" :size="40" variant="mini" />
                <span class="col">
                    <b>{{ m.me ? '나' : m.user.nickname }}<span v-if="m.host" class="crown" aria-label="호스트"> 👑</span></b>
                    <small :class="{ live: m.position || (m.me && board.me_sharing), done: m.arrived }">{{ statusText(m) }}</small>
                </span>
            </button>
        </div>
    </div>
</template>

<style scoped>
.mm { height: 100%; display: flex; flex-direction: column; }
.head { display: flex; align-items: center; gap: 4px; padding: 10px 14px 8px 4px; }
.icon { width: 44px; height: 44px; border: none; background: none; display: flex; align-items: center; justify-content: center; }
.title-col { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.title-col h1 { margin: 0; font-size: 18px; color: var(--c-ink); }
.title-col small { font-size: 12px; color: var(--c-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dest-btn { height: 36px; padding: 0 12px; border: none; border-radius: 12px; background: var(--c-primary-soft); color: var(--c-primary-deep); font-size: 13px; font-weight: 700; }
.map { flex: 1; min-height: 240px; }
.nomap { margin: 8px 16px 0; font-size: 13px; color: var(--c-muted); }
.panel { max-height: 48%; padding: 14px 16px 24px; display: flex; flex-direction: column; gap: 6px; background: var(--c-surface); border-radius: 20px 20px 0 0; box-shadow: 0 -4px 16px rgba(31, 27, 58, .08); }
.panel.full { flex: 1; max-height: none; }
.dest { margin: 0 0 4px; display: flex; flex-direction: column; gap: 2px; }
.dest b { font-size: 15px; color: var(--c-ink); }
.dest small { font-size: 12px; color: var(--c-muted); }
.share-on { min-height: 60px; border: none; border-radius: 14px; background: var(--c-primary); color: #fff; font-size: 16px; font-weight: 700; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; }
.share-on small { font-size: 12px; font-weight: 400; opacity: .9; }
.share-state { min-height: 52px; padding: 0 8px 0 14px; border-radius: 14px; background: #E3F6EC; color: var(--c-live-ink); font-size: 14px; font-weight: 700; display: flex; align-items: center; justify-content: space-between; }
.share-off { height: 36px; padding: 0 14px; border: 1.5px solid var(--c-live-ink); border-radius: 18px; background: #fff; color: var(--c-live-ink); font-size: 13px; font-weight: 700; }
.closed { margin: 0; font-size: 13px; color: var(--c-muted); }
.row { display: flex; align-items: center; gap: 12px; border: none; background: none; padding: 8px 0; text-align: left; min-height: 56px; }
.col { flex: 1; display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.col b { font-size: 15px; color: var(--c-ink); }
.col small { font-size: 12px; color: var(--c-muted); }
.col small.live { color: var(--c-live-ink); }
.col small.done { color: #1E7A4C; font-weight: 700; }
</style>

<!-- 지도 마커는 카카오맵이 지도 안에 직접 붙이는 DOM이라 scoped 스타일이 닿지 않음 → 전역(mm- 접두어) -->
<style>
.mm-pin { display: flex; flex-direction: column; align-items: center; }
.mm-dot { width: 22px; height: 22px; border-radius: 11px; border: 3px solid #fff; box-shadow: 0 2px 6px rgba(31, 27, 58, .3); }
.mm-name { margin-top: 2px; padding: 2px 8px; border-radius: 10px; background: #fff; color: #1F1B3A; font-size: 12px; font-weight: 700; box-shadow: 0 1px 4px rgba(31, 27, 58, .2); white-space: nowrap; }
.mm-me .mm-name { background: #6C5CE7; color: #fff; }
.mm-dest { display: flex; flex-direction: column; align-items: center; }
.mm-dest-name { padding: 6px 10px; border-radius: 12px; background: #E8453C; color: #fff; font-size: 13px; font-weight: 800; box-shadow: 0 3px 8px rgba(0, 0, 0, .25); white-space: nowrap; max-width: 200px; overflow: hidden; text-overflow: ellipsis; }
.mm-dest-stick { width: 3px; height: 14px; background: #E8453C; }
</style>
