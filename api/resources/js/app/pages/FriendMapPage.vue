<script setup>
/**
 * 친구 지도
 * - 나에게 위치를 공유 중인 친구를 지도에 표시 (카카오맵). 키가 없거나 지도 로딩 실패 시 목록만
 * - 실시간: 이 화면을 연 동안만 location.{내 id} 채널을 구독 → 화면을 나가면 좌표를 받지 않음
 * - 2분마다 목록을 다시 받아 새로 공유한 친구·만료된 공유를 반영
 * - 상단 '내 위치 공유'로 내 공유 설정 시트
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import { useRouter } from 'vue-router';
import AvatarFace from '../components/AvatarFace.vue';
import LocationShareSheet from '../components/LocationShareSheet.vue';
import { ApiError, get } from '../lib/api';
import { auth } from '../lib/auth';
import { colorHex } from '../lib/avatar';
import { connectEcho } from '../lib/echo';
import { loadKakaoMaps } from '../lib/kakaoMap';
import { distanceText, locShare, meters, remainText } from '../lib/locationShare';
import { ago } from '../lib/time';
import { showToast } from '../lib/toast';

const router = useRouter();

const friends = ref([]);          // [{user, position, until}]
const myPos = ref(null);          // {lat, lng}
const mapEl = ref(null);
const map = shallowRef(null);     // 카카오 지도 객체 (반응형 추적 불필요)
const noMap = ref(false);
const sheet = ref(false);
const now = ref(Date.now());      // 'N분 전' 표시 갱신용

const overlays = new Map();       // user id → CustomOverlay
let meOverlay = null;
let channel = null;
let refreshTimer = null;
let clockTimer = null;
let fitted = false;

const located = computed(() => friends.value.filter((f) => f.position));
const sorted = computed(() => [...friends.value].sort((a, b) => (b.position ? 1 : 0) - (a.position ? 1 : 0)));

async function load() {
    try {
        friends.value = (await get('/location/friends')).data;
        drawAll();
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '친구 위치를 불러오지 못했어요');
    }
}

function locateMe() {
    return new Promise((resolve) => {
        if (!navigator.geolocation) return resolve(null);
        navigator.geolocation.getCurrentPosition(
            (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude }),
            () => resolve(null),
            { enableHighAccuracy: true, timeout: 8000, maximumAge: 30_000 },
        );
    });
}

/** 마커 DOM을 직접 만든다 (닉네임을 HTML 문자열로 넣지 않음 → 스크립트 삽입 방지) */
function pinElement(label, color, isMe = false) {
    const el = document.createElement('div');
    el.className = isMe ? 'fm-pin fm-me' : 'fm-pin';
    const dot = document.createElement('span');
    dot.className = 'fm-dot';
    dot.style.background = color;
    const name = document.createElement('span');
    name.className = 'fm-name';
    name.textContent = label;
    el.append(dot, name);
    return el;
}

function drawAll() {
    const kakao = window.kakao;
    if (!map.value || !kakao) return;

    // 사라진 친구(공유 끔·만료) 마커 제거
    const alive = new Set(located.value.map((f) => f.user.id));
    for (const [id, o] of overlays) if (!alive.has(id)) { o.setMap(null); overlays.delete(id); }

    for (const f of located.value) drawFriend(f);
    if (myPos.value) {
        const pos = new kakao.maps.LatLng(myPos.value.lat, myPos.value.lng);
        if (!meOverlay) meOverlay = new kakao.maps.CustomOverlay({ position: pos, content: pinElement('나', '#6C5CE7', true), yAnchor: 1, zIndex: 1 });
        meOverlay.setPosition(pos);
        meOverlay.setMap(map.value);
    }

    // 처음 한 번만: 나와 친구들이 다 보이게 범위 맞춤 (이후엔 사용자가 움직인 화면을 유지)
    if (!fitted && (located.value.length || myPos.value)) {
        const b = new kakao.maps.LatLngBounds();
        located.value.forEach((f) => b.extend(new kakao.maps.LatLng(f.position.lat, f.position.lng)));
        if (myPos.value) b.extend(new kakao.maps.LatLng(myPos.value.lat, myPos.value.lng));
        if (located.value.length + (myPos.value ? 1 : 0) > 1) map.value.setBounds(b, 60, 40, 220, 40);
        else map.value.setCenter(b.getSouthWest());
        fitted = true;
    }
}

function drawFriend(f) {
    const kakao = window.kakao;
    if (!map.value || !kakao || !f.position) return;
    const pos = new kakao.maps.LatLng(f.position.lat, f.position.lng);
    let o = overlays.get(f.user.id);
    if (!o) {
        o = new kakao.maps.CustomOverlay({ position: pos, content: pinElement(f.user.nickname, colorHex(f.user.avatar?.color)), yAnchor: 1, zIndex: 2 });
        o.setMap(map.value);
        overlays.set(f.user.id, o);
    }
    o.setPosition(pos);
}

function focus(f) {
    if (!f.position) return showToast(`${f.user.nickname}님이 앱을 열면 위치가 보여요`);
    if (map.value) map.value.panTo(new window.kakao.maps.LatLng(f.position.lat, f.position.lng));
}

/** 실시간 수신: 목록에 있는 친구만 갱신 (목록에 없으면 새 공유 → 목록을 다시 받음) */
function onUpdated(p) {
    const f = friends.value.find((x) => x.user.id === p.user_id);
    if (!f) return load();
    f.position = p;
    drawFriend(f);
}

function onStopped({ user_id: id }) {
    friends.value = friends.value.filter((f) => f.user.id !== id);
    overlays.get(id)?.setMap(null);
    overlays.delete(id);
}

function statusText(f) {
    void now.value; // 시간이 흐르면 다시 계산
    if (!f.position) return '앱을 열면 위치가 보여요';
    const parts = [ago(f.position.at)];
    if (myPos.value) parts.push(distanceText(meters(myPos.value, f.position)));
    return parts.join(' · ');
}

onMounted(async () => {
    if (!auth.me) auth.me = (await get('/me')).data;

    channel = `location.${auth.me.id}`;
    connectEcho().private(channel)
        .listen('.location.updated', onUpdated)
        .listen('.location.stopped', onStopped);

    const [kakao, pos] = await Promise.all([loadKakaoMaps(), locateMe(), load()]);
    myPos.value = pos;

    if (kakao && mapEl.value) {
        await nextTick();
        const center = pos ?? located.value[0]?.position ?? { lat: 37.5665, lng: 126.978 }; // 기본: 서울시청
        map.value = new kakao.maps.Map(mapEl.value, { center: new kakao.maps.LatLng(center.lat, center.lng), level: 5 });
        drawAll();
    } else {
        noMap.value = true;
    }

    refreshTimer = setInterval(load, 2 * 60_000);
    clockTimer = setInterval(() => (now.value = Date.now()), 30_000);
});

onBeforeUnmount(() => {
    if (channel) window.Echo?.leave(channel);
    clearInterval(refreshTimer);
    clearInterval(clockTimer);
    overlays.forEach((o) => o.setMap(null));
    meOverlay?.setMap(null);
});
</script>

<template>
    <div class="fm">
        <div class="head">
            <button class="icon" aria-label="뒤로가기" @click="router.back()">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
            </button>
            <h1 class="title" style="font-size: 22px; flex: 1">친구 지도</h1>
            <button class="share" :class="{ on: locShare.shares.length }" @click="sheet = true">
                📍 {{ locShare.shares.length ? `공유 중 ${locShare.shares.length}` : '내 위치 공유' }}
            </button>
        </div>

        <div v-show="!noMap" ref="mapEl" class="map" />
        <p v-if="noMap" class="nomap">지도를 불러오지 못해 목록으로 보여드려요.</p>

        <div class="panel scroll" :class="{ full: noMap }">
            <p v-if="!friends.length" class="empty">
                아직 나에게 위치를 공유한 친구가 없어요.<br>친구가 공유를 켜면 여기에 보여요.
            </p>
            <button v-for="f in sorted" :key="f.user.id" class="row" @click="focus(f)">
                <AvatarFace :parts="f.user.avatar" :size="40" variant="mini" />
                <span class="col">
                    <b>{{ f.user.nickname }}</b>
                    <small :class="{ live: f.position }">{{ statusText(f) }}</small>
                </span>
                <span class="until">{{ remainText(f.until) }}</span>
            </button>
        </div>

        <LocationShareSheet v-if="sheet" @close="sheet = false" />
    </div>
</template>

<style scoped>
.fm { height: 100%; display: flex; flex-direction: column; position: relative; }
.head { display: flex; align-items: center; gap: 4px; padding: 14px 16px 8px 8px; }
.icon { width: 44px; height: 44px; border: none; background: none; display: flex; align-items: center; justify-content: center; }
.share { height: 36px; padding: 0 12px; border: none; border-radius: 12px; background: var(--c-primary-soft); color: var(--c-primary-deep); font-size: 13px; font-weight: 700; }
.share.on { background: #E3F6EC; color: var(--c-live-ink); }
.map { flex: 1; min-height: 240px; }
.nomap { margin: 8px 16px 0; font-size: 13px; color: var(--c-muted); }
.panel { max-height: 40%; padding: 12px 16px 24px; display: flex; flex-direction: column; gap: 4px; background: var(--c-surface); border-radius: 20px 20px 0 0; box-shadow: 0 -4px 16px rgba(31, 27, 58, .08); }
.panel.full { flex: 1; max-height: none; }
.empty { text-align: center; color: var(--c-muted); font-size: 14px; line-height: 1.6; margin: 12px 0; }
.row { display: flex; align-items: center; gap: 12px; border: none; background: none; padding: 8px 0; text-align: left; }
.col { flex: 1; display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.col b { font-size: 15px; color: var(--c-ink); }
.col small { font-size: 12px; color: var(--c-muted); }
.col small.live { color: var(--c-live-ink); }
.until { font-size: 12px; color: var(--c-muted); }
</style>

<!-- 지도 마커는 카카오맵이 지도 안에 직접 붙이는 DOM이라 scoped 스타일이 닿지 않음 → 전역(fm- 접두어) -->
<style>
.fm-pin { display: flex; flex-direction: column; align-items: center; transform: translateY(-2px); }
.fm-dot { width: 22px; height: 22px; border-radius: 11px; border: 3px solid #fff; box-shadow: 0 2px 6px rgba(31, 27, 58, .3); }
.fm-name { margin-top: 2px; padding: 2px 8px; border-radius: 10px; background: #fff; color: #1F1B3A; font-size: 12px; font-weight: 700; box-shadow: 0 1px 4px rgba(31, 27, 58, .2); white-space: nowrap; }
.fm-me .fm-name { background: #6C5CE7; color: #fff; }
</style>
