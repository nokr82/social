<script setup>
/**
 * 장소 하나를 핀으로 보여주는 작은 지도 (번개 만들기·번개 상세).
 * - 카카오맵 JS SDK는 lib/kakaoMap.js가 한 번만 불러옴
 * - 불러오는 동안은 빈 칸(크기를 먼저 잡아야 지도가 제대로 그려짐 — display:none 안에서 만들면 크기 0)
 * - 키가 없거나 불러오기 실패면 칸을 없앰 → 화면은 주소·'카카오맵에서 보기' 링크만으로 충분
 * - 움직이지 않는 지도(드래그·확대 끔): 스크롤하다 지도에 손가락이 걸려 화면이 안 내려가는 문제 방지
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { loadKakaoMaps } from '../lib/kakaoMap';

const props = defineProps({
    lat: { type: Number, required: true },
    lng: { type: Number, required: true },
    height: { type: Number, default: 140 },
});

const el = ref(null);
const failed = ref(false);
let map = null;
let marker = null;
let alive = true;

onMounted(async () => {
    const kakao = await loadKakaoMaps();
    if (!alive || !el.value) return;
    if (!kakao) { failed.value = true; return; }
    const pos = new kakao.maps.LatLng(props.lat, props.lng);
    map = new kakao.maps.Map(el.value, { center: pos, level: 3, draggable: false, scrollwheel: false, disableDoubleClickZoom: true });
    marker = new kakao.maps.Marker({ position: pos, map });
});

// 다른 장소를 고르면 핀만 옮김 (지도를 새로 만들지 않음)
watch(() => [props.lat, props.lng], ([lat, lng]) => {
    if (!map) return;
    const pos = new window.kakao.maps.LatLng(lat, lng);
    map.setCenter(pos);
    marker.setPosition(pos);
});

onBeforeUnmount(() => { alive = false; });
</script>

<template>
    <div v-if="!failed" ref="el" class="place-map" :style="{ height: `${height}px` }" aria-hidden="true" />
</template>

<style scoped>
.place-map { width: 100%; border-radius: 12px; overflow: hidden; background: var(--c-surface); }
</style>
