<script setup>
/**
 * 대화방 위치 카드 (DM에서 '위치 공유'를 켜면 남는 메시지)
 * - 받은 사람 + 최신 카드(live): 작은 지도에 친구의 정확한 위치 핀. 30초마다 갱신, 누르면 친구 지도로
 * - 받은 사람 + 지난 카드: 지도 없이 상태만 (같은 위치를 여러 장 그리지 않게)
 * - 보낸 사람: '내 위치를 공유하고 있어요 · 남은 시간'
 * 좌표는 메시지에 없다 → 볼 때마다 /api/location/friends에서 받음 (공유가 끝나면 더 이상 안 보임)
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { useRouter } from 'vue-router';
import { get } from '../lib/api';
import { loadKakaoMaps } from '../lib/kakaoMap';
import { remainText } from '../lib/locationShare';
import { ago } from '../lib/time';

const props = defineProps({
    message: { type: Object, required: true },
    mine: { type: Boolean, default: false },
    live: { type: Boolean, default: false },
});
const router = useRouter();

const loc = computed(() => props.message.location ?? {});
const active = ref(!!loc.value.active);
const until = ref(loc.value.until ?? null);
const position = ref(null);     // {lat, lng, at}
const loaded = ref(false);
const mapEl = ref(null);
const map = shallowRef(null);
let pin = null;
let timer = null;

const showMap = computed(() => !props.mine && props.live && active.value);

const status = computed(() => {
    if (!active.value) return '위치 공유가 끝났어요';
    if (props.mine) return `내 위치를 공유하고 있어요 · ${remainText(until.value)}`;
    if (!props.live) return '위치를 공유했어요';
    if (!loaded.value) return '위치를 불러오는 중…';
    if (!position.value) return `친구가 앱을 열면 위치가 보여요 · ${remainText(until.value)}`;
    return `${ago(position.value.at)} 위치 · ${remainText(until.value)}`;
});

/** 친구 위치 받기. 목록에 없으면 공유가 끝난 것 */
async function refresh() {
    try {
        const list = (await get('/location/friends')).data;
        const f = list.find((x) => x.user.id === loc.value.sharer_id);
        active.value = !!f;
        position.value = f?.position ?? null;
        until.value = f?.until ?? until.value;
    } catch {
        /* 일시 오류: 다음 주기에 */
    } finally {
        loaded.value = true;
    }
}

/** 작은 지도: 끌기·확대 막고 핀 하나만 (자세히 보려면 눌러서 친구 지도로) */
async function drawMap() {
    await nextTick(); // v-if로 지도 칸이 막 생긴 경우 DOM이 붙을 때까지 기다림
    if (!position.value || !mapEl.value) {
        map.value = null; // 칸이 사라졌으면(공유 끝남) 다음에 새로 만든다
        return;
    }
    const kakao = await loadKakaoMaps();
    if (!kakao) return;
    const pos = new kakao.maps.LatLng(position.value.lat, position.value.lng);
    if (!map.value) {
        map.value = new kakao.maps.Map(mapEl.value, { center: pos, level: 4, draggable: false, scrollwheel: false, disableDoubleClickZoom: true });
        pin = new kakao.maps.Marker({ position: pos });
        pin.setMap(map.value);
    }
    pin.setPosition(pos);
    map.value.setCenter(pos);
}

watch(position, drawMap);

function open() {
    if (props.mine) return router.push({ name: 'map' });
    if (active.value) router.push({ name: 'map', query: { focus: loc.value.sharer_id } });
}

onMounted(() => {
    if (!showMap.value) return;
    refresh();
    timer = setInterval(refresh, 30_000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <button class="loc" :class="{ mine, dead: !active }" :aria-label="`위치 공유 카드: ${status}`" @click="open">
        <span class="head">
            <span class="pinic" aria-hidden="true">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z" /><circle cx="12" cy="10" r="2.5" /></svg>
            </span>
            위치 공유
        </span>
        <span v-if="showMap && position" ref="mapEl" class="map" />
        <span class="st">{{ status }}</span>
        <span v-if="active && !mine" class="go">지도에서 보기</span>
    </button>
</template>

<style scoped>
.loc { width: 230px; text-align: left; border: 1.5px solid var(--c-primary-soft); background: var(--c-surface); border-radius: 18px; padding: 12px 14px; display: flex; flex-direction: column; gap: 6px; }
.loc.mine { border-color: var(--c-primary); }
.loc.dead { opacity: .6; }
.head { display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 700; color: var(--c-primary-deep); }
.pinic { width: 22px; height: 22px; border-radius: 11px; background: var(--c-live-ink); display: flex; align-items: center; justify-content: center; }
.map { display: block; width: 100%; height: 140px; border-radius: 12px; overflow: hidden; background: var(--c-primary-soft); }
.st { font-size: 13px; color: var(--c-ink); }
.go { font-size: 13px; font-weight: 700; color: var(--c-primary-ink); }
</style>
