<script setup>
/**
 * 번개 장소 고르기 (전체 화면, 카카오T 방식)
 * - 화면 가운데 핀은 고정, 지도를 끌어서 위치를 맞춤 → 멈추면 0.4초 뒤 서버로 주소·건물 이름 받기
 * - 위 검색창: 결과를 누르면 지도가 그 장소로 이동하고 이름이 채워짐 (핀을 안 움직이면 카카오 장소 id 유지)
 * - 장소 이름은 고칠 수 있음 → 이름 없는 곳(공원 정문 등)도 핀 + 이름으로 해결 ('직접 입력' 모드 불필요)
 * - 범위(주민 광장 5km·동네 광장 같은 구)는 서버가 핀마다 reachable로 알려줌 → 밖이면 핀 빨강·버튼 막음
 *   (번개를 만들 때 서버가 다시 확인하므로 화면 표시는 안내용)
 * - 지도를 못 불러오면(키 없음 등) 검색 목록만 + '글로만 적기'
 * 열고 닫기는 부모가 주소 ?pick=1로 관리 → 휴대폰 뒤로가기는 이 화면만 닫음
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { ApiError, get } from '../lib/api';
import { loadKakaoMaps } from '../lib/kakaoMap';
import { getLocation } from '../lib/location';
import { showToast } from '../lib/toast';

const props = defineProps({
    plazaId: { type: String, required: true },
    initial: { type: Object, default: null }, // 이미 고른 장소 (바꾸기로 다시 열 때)
});
const emit = defineEmits(['select', 'close', 'manual']);

const SEOUL = { lat: 37.5665, lng: 126.978 };

const mapEl = ref(null);
const mapFailed = ref(false);
const moving = ref(false);
const loadingAddr = ref(false);
const addr = ref(null);            // {address, jibun, building_name, reachable}
const name = ref(props.initial?.name ?? '');
const nameTouched = ref(!!props.initial);
const fromSearch = ref(props.initial?.kakao_id ? props.initial : null); // 검색으로 고른 장소 (핀을 움직이면 해제)
const scope = ref(null);

const query = ref('');
const results = ref([]);
const searching = ref(false);
const searchMsg = ref('');

let kakao = null;
let map = null;
let myPos = null;
let idleTimer = null;
let searchTimer = null;
let addrSeq = 0;
let searchSeq = 0;
let center = null;

const reachable = computed(() => addr.value?.reachable !== false);
const canConfirm = computed(() => !!center && !moving.value && !loadingAddr.value && !!addr.value && reachable.value && !!name.value.trim());
const outOfRangeText = computed(() => (scope.value?.kind === 'town' ? '이 동네 밖이에요' : '광장에서 5km 밖이에요'));

/** 두 좌표가 거의 같은지 (약 5m) — 검색 결과로 이동한 직후의 idle인지 판단 */
function near(a, b) {
    return a && b && Math.abs(a.lat - b.lat) < 0.00005 && Math.abs(a.lng - b.lng) < 0.00005;
}

async function onIdle() {
    const c = map.getCenter();
    center = { lat: c.getLat(), lng: c.getLng() };
    moving.value = false;
    // 핀을 검색 결과에서 움직였으면 더 이상 그 가게가 아님 → 장소 id·이름 해제
    if (fromSearch.value && !near(center, fromSearch.value)) {
        fromSearch.value = null;
        if (!nameTouched.value) name.value = '';
    }
    clearTimeout(idleTimer);
    idleTimer = setTimeout(fetchAddress, 400); // 끌다 멈춘 뒤 0.4초 — 쿼터 절약
}

async function fetchAddress() {
    const my = ++addrSeq;
    loadingAddr.value = true;
    const q = new URLSearchParams({ plaza_id: props.plazaId, lat: center.lat, lng: center.lng });
    try {
        const res = (await get(`/places/address?${q}`)).data;
        if (my !== addrSeq) return;
        addr.value = res;
        // 이름 자동 채우기: 검색으로 고른 가게 > 건물 이름 (직접 고친 이름은 덮지 않음)
        if (!nameTouched.value && !fromSearch.value) name.value = res.building_name ?? '';
    } catch (e) {
        if (my !== addrSeq) return;
        addr.value = null;
        showToast(e instanceof ApiError ? e.message : '주소를 불러오지 못했어요');
    } finally {
        if (my === addrSeq) loadingAddr.value = false;
    }
}

function moveTo(lat, lng) {
    if (!map) return;
    map.setLevel(3);
    map.setCenter(new kakao.maps.LatLng(lat, lng)); // → idle 이벤트 → 주소 갱신
}

// ── 검색 ──
watch(query, (q) => {
    clearTimeout(searchTimer);
    searchMsg.value = '';
    if (!q.trim()) { results.value = []; return; }
    searchTimer = setTimeout(() => runSearch(q.trim()), 300);
});

async function runSearch(q) {
    const my = ++searchSeq;
    searching.value = true;
    const params = new URLSearchParams({ q, plaza_id: props.plazaId });
    if (myPos) { params.set('lat', myPos.lat); params.set('lng', myPos.lng); }
    try {
        const res = await get(`/places/search?${params}`);
        if (my !== searchSeq) return;
        results.value = res.data;
        searchMsg.value = res.data.length ? '' : '이 광장 범위에서 찾지 못했어요. 지도를 옮겨 핀으로 정해 보세요';
    } catch (e) {
        if (my !== searchSeq) return;
        results.value = [];
        searchMsg.value = e instanceof ApiError ? e.message : '장소를 찾지 못했어요';
    } finally {
        if (my === searchSeq) searching.value = false;
    }
}

function pickResult(p) {
    searchSeq++;
    results.value = [];
    query.value = '';
    fromSearch.value = p;
    name.value = p.name;
    nameTouched.value = false;
    if (map) {
        moveTo(p.lat, p.lng);
    } else {
        // 지도 없는 모드: 검색 결과를 그대로 장소로 (검색 결과는 이미 범위 안만 옴)
        center = { lat: p.lat, lng: p.lng };
        addr.value = { address: p.address, jibun: p.jibun, building_name: null, reachable: true };
    }
}

async function goMyLocation() {
    const l = await getLocation();
    if (l.fallback) return showToast('위치 권한을 켜면 내 위치로 이동할 수 있어요');
    myPos = { lat: l.lat, lng: l.lng };
    moveTo(l.lat, l.lng);
}

function confirm() {
    if (!canConfirm.value) return;
    const f = fromSearch.value;
    emit('select', {
        name: name.value.trim(),
        address: f?.address || addr.value.address || addr.value.jibun,
        jibun: f?.jibun || addr.value.jibun,
        lat: f ? f.lat : center.lat,
        lng: f ? f.lng : center.lng,
        kakao_id: f?.kakao_id ?? null,
    });
}

onMounted(async () => {
    const [sc, loc, k] = await Promise.all([
        get(`/places/scope?plaza_id=${props.plazaId}`).then((r) => r.data).catch(() => null),
        getLocation(),
        loadKakaoMaps(),
    ]);
    scope.value = sc;
    if (!loc.fallback) myPos = { lat: loc.lat, lng: loc.lng }; // 기본 위치 좌표는 서버에 보내지 않음

    if (!k || !mapEl.value) { mapFailed.value = true; return; }
    kakao = k;

    // 처음 위치: 고른 장소 > 주민 광장 위치 > 내 위치 > 광장 위치 > 서울
    const start = props.initial
        ?? (sc?.kind === 'user' ? sc.center : null)
        ?? myPos ?? sc?.center ?? SEOUL;
    const pos = new kakao.maps.LatLng(start.lat, start.lng);
    map = new kakao.maps.Map(mapEl.value, { center: pos, level: 3 });

    // 주민 광장: 고를 수 있는 범위를 원으로 (동네 광장은 경계가 다각형이라 핀마다 안내만)
    if (sc?.radius_m && sc.center) {
        new kakao.maps.Circle({
            map, center: new kakao.maps.LatLng(sc.center.lat, sc.center.lng), radius: sc.radius_m,
            strokeWeight: 2, strokeColor: '#5B4BD6', strokeOpacity: 0.5, strokeStyle: 'dash',
            fillColor: '#5B4BD6', fillOpacity: 0.04,
        });
    }

    kakao.maps.event.addListener(map, 'dragstart', () => { moving.value = true; });
    kakao.maps.event.addListener(map, 'idle', onIdle);
    onIdle(); // 처음 위치의 주소
});

onBeforeUnmount(() => {
    clearTimeout(idleTimer);
    clearTimeout(searchTimer);
});
</script>

<template>
    <div class="picker" role="dialog" aria-modal="true" aria-label="만날 장소 고르기">
        <!-- 위: 닫기 + 검색 -->
        <div class="top">
            <button class="icon" aria-label="닫기" @click="emit('close')">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
            </button>
            <label class="search-box">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6B6785" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" /></svg>
                <span class="sr-only">장소 검색</span>
                <input v-model="query" type="search" maxlength="40" placeholder="장소 검색 (가게·역·건물 이름)" enterkeyhint="search">
            </label>
        </div>
        <ul v-if="results.length" class="results" role="listbox" aria-label="장소 검색 결과">
            <li v-for="p in results" :key="p.kakao_id ?? `${p.lat},${p.lng}`">
                <button class="result" role="option" @click="pickResult(p)">
                    <span class="col">
                        <b>{{ p.name }}</b>
                        <small>{{ [p.category, p.address].filter(Boolean).join(' · ') }}</small>
                    </span>
                    <span v-if="p.distance_label" class="dist">{{ p.distance_label }}</span>
                </button>
            </li>
        </ul>
        <p v-else-if="searching || searchMsg" class="search-note">{{ searching ? '찾는 중…' : searchMsg }}</p>

        <!-- 지도 + 가운데 고정 핀 -->
        <div v-if="!mapFailed" class="map-wrap">
            <div ref="mapEl" class="map" />
            <div class="pin" :class="{ lift: moving, bad: !reachable && !moving }" aria-hidden="true">
                <svg width="40" height="48" viewBox="0 0 40 48"><path d="M20 47s16-15.2 16-27A16 16 0 0 0 4 20c0 11.8 16 27 16 27z" fill="currentColor" /><circle cx="20" cy="19" r="6.5" fill="#fff" /></svg>
                <span class="shadow" />
            </div>
            <span v-if="!moving && !addr && !loadingAddr" class="hint-bubble">지도를 움직여 핀을 맞춰 주세요</span>
            <button class="my-loc" aria-label="내 위치로" @click="goMyLocation">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4" /><path d="M12 2v3M12 19v3M2 12h3M19 12h3" /></svg>
            </button>
        </div>
        <div v-else class="no-map">
            <p>지도를 불러오지 못했어요. 위에서 장소를 검색해 고르거나, 장소를 글로만 적을 수 있어요.</p>
            <button class="ghost" @click="emit('manual')">장소를 글로만 적기</button>
        </div>

        <!-- 아래 카드: 이름·주소·정하기 -->
        <div class="sheet">
            <label class="name">
                <span>장소 이름 · 참여자에게 보여요</span>
                <input v-model="name" maxlength="100" placeholder="예) 역삼공원 정문, ○○카페" @input="nameTouched = true">
            </label>
            <p class="addr">
                <template v-if="moving">위치를 맞추는 중…</template>
                <template v-else-if="loadingAddr">주소 확인 중…</template>
                <template v-else-if="addr">{{ addr.address || addr.jibun || '주소 없는 곳이에요' }}</template>
                <template v-else>&nbsp;</template>
            </p>
            <button class="btn-primary" :disabled="!canConfirm" @click="confirm">
                {{ addr && !reachable && !moving ? outOfRangeText : '이 위치로 정하기' }}
            </button>
        </div>
    </div>
</template>

<style scoped>
.picker { position: fixed; inset: 0; z-index: 50; background: var(--c-bg, #fff); display: flex; flex-direction: column; }
.top { position: relative; display: flex; align-items: center; gap: 4px; padding: 10px 12px 8px 4px; background: var(--c-surface); z-index: 3; box-shadow: 0 2px 8px rgba(31, 27, 58, .08); }
.icon { width: 44px; height: 44px; border: none; background: none; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.search-box { flex: 1; height: 48px; border: 2px solid var(--c-primary); border-radius: 14px; padding: 0 14px; display: flex; align-items: center; gap: 8px; background: var(--c-surface); }
.search-box:focus-within { border-color: var(--c-primary); }
.search-box input { flex: 1; min-width: 0; border: none; outline: none; background: transparent; font-size: 15px; color: var(--c-ink); }
.results { position: absolute; top: 66px; left: 12px; right: 12px; z-index: 4; list-style: none; margin: 0; padding: 4px 0; border-radius: 14px; background: var(--c-surface); box-shadow: 0 8px 24px rgba(31, 27, 58, .16); max-height: 55%; overflow-y: auto; }
.result { width: 100%; min-height: 56px; display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 14px; border: none; background: none; text-align: left; }
.result:active { background: var(--c-primary-soft); }
.col { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.result b { font-size: 14px; color: var(--c-ink); }
.result small { font-size: 12px; color: var(--c-muted); }
.dist { flex-shrink: 0; font-size: 12px; font-weight: 700; color: var(--c-primary-deep); }
.search-note { position: absolute; top: 66px; left: 12px; right: 12px; z-index: 4; margin: 0; padding: 12px 14px; border-radius: 14px; background: var(--c-surface); box-shadow: 0 8px 24px rgba(31, 27, 58, .12); font-size: 13px; color: var(--c-muted); }
/* isolation: 카카오맵 내부 레이어(z-index가 큼)가 검색 결과 목록을 덮지 않게 지도 영역 안에 가둠 */
.map-wrap { position: relative; flex: 1; min-height: 0; isolation: isolate; z-index: 0; }
.map { position: absolute; inset: 0; }
/* 핀 끝이 지도 정중앙에 오도록: 핀 높이만큼 위로 */
.pin { position: absolute; left: 50%; top: 50%; transform: translate(-50%, -100%); color: var(--c-primary); z-index: 2; pointer-events: none; transition: transform .15s; display: flex; flex-direction: column; align-items: center; }
.pin svg { filter: drop-shadow(0 3px 4px rgba(0, 0, 0, .25)); }
.pin.lift { transform: translate(-50%, calc(-100% - 10px)); }
.pin.bad { color: var(--c-danger); }
.pin .shadow { position: absolute; bottom: -3px; width: 10px; height: 4px; border-radius: 50%; background: rgba(0, 0, 0, .3); }
.hint-bubble { position: absolute; left: 50%; top: calc(50% - 76px); transform: translateX(-50%); z-index: 2; white-space: nowrap; background: var(--c-ink); color: #fff; font-size: 12px; font-weight: 700; padding: 6px 10px; border-radius: 12px; pointer-events: none; }
.my-loc { position: absolute; right: 14px; bottom: 14px; z-index: 2; width: 48px; height: 48px; border-radius: 24px; border: none; background: var(--c-surface); box-shadow: 0 4px 12px rgba(31, 27, 58, .2); display: flex; align-items: center; justify-content: center; }
.no-map { flex: 1; padding: 100px 24px 24px; display: flex; flex-direction: column; gap: 14px; align-items: flex-start; }
.no-map p { margin: 0; font-size: 14px; color: var(--c-ink-2); line-height: 1.5; }
.ghost { height: 44px; padding: 0 16px; border: 1.5px solid var(--c-line); border-radius: 22px; background: var(--c-surface); font-size: 14px; font-weight: 700; color: var(--c-ink); }
.sheet { position: relative; padding: 16px 20px 26px; background: var(--c-surface); border-radius: 20px 20px 0 0; box-shadow: 0 -6px 20px rgba(31, 27, 58, .1); display: flex; flex-direction: column; gap: 10px; z-index: 3; }
.name { display: flex; flex-direction: column; gap: 4px; }
.name span { font-size: 12px; color: var(--c-muted); }
.name input { height: 50px; border: 1.5px solid var(--c-line); border-radius: 14px; padding: 0 14px; font-size: 16px; font-weight: 700; color: var(--c-ink); background: var(--c-surface); outline: none; }
.name input:focus { border-color: var(--c-primary); }
.addr { margin: 0; font-size: 13px; color: var(--c-muted); min-height: 18px; }
</style>
