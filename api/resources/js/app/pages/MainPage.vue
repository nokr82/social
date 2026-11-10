<script setup>
/**
 * 메인 — 광장 미리보기형 (디자인: MainGrid.dc.html, C안 채택)
 * - 정렬 탭: 가까운 순 / 북적이는 순 / 새로 생긴  → GET /plazas?sort=
 * - 타일: 광장 안 접속자 아바타 + 방금 한 말(말풍선) + 접속자 수
 * - 접속자 수가 계속 바뀌므로 20초마다 새로고침
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import AvatarFace from '../components/AvatarFace.vue';
import CreatePlazaSheet from '../components/CreatePlazaSheet.vue';
import TabBar from '../components/TabBar.vue';
import { ApiError, get } from '../lib/api';
import { auth } from '../lib/auth';
import { CATEGORY_GROUND } from '../lib/avatar';
import { getLocation } from '../lib/location';
import { showToast } from '../lib/toast';

const router = useRouter();

const SORTS = [
    { key: 'near', label: '가까운 순' },
    { key: 'busy', label: '북적이는 순' },
    { key: 'new', label: '새로 생긴' },
];
// 타일 안 아바타 자리 (디자인 시안의 배치를 그대로) — 접속자 순서대로 채운다
const SLOTS = [
    { x: 30, y: 52 }, { x: 64, y: 40 }, { x: 96, y: 60 }, { x: 56, y: 76 },
];

const sort = ref('near');
const plazas = ref([]);
const loading = ref(true);
const location = ref({ label: '' });
const creating = ref(false);
let timer = null;

const tiles = computed(() => plazas.value.map((p, i) => ({
    ...p,
    ground: CATEGORY_GROUND[p.category] ?? '#E9E4FF',
    avatars: p.preview_avatars.slice(0, SLOTS.length).map((parts, j) => ({ parts, ...SLOTS[j] })),
    // 말풍선 위치를 타일마다 조금씩 다르게 (디자인처럼 자연스럽게)
    bubbleX: [14, 60, 30, 50, 20, 56][i % 6],
    meta: [p.category_label, p.distance_label].filter(Boolean).join(' · '),
})));

async function load({ silent = false } = {}) {
    if (!silent) loading.value = true;
    try {
        const loc = await getLocation();
        location.value = loc;
        const res = await get(`/plazas?lat=${loc.lat}&lng=${loc.lng}&sort=${sort.value}`);
        plazas.value = res.data;
    } catch (e) {
        if (!silent) showToast(e instanceof ApiError ? e.message : '광장 목록을 불러오지 못했어요');
    } finally {
        loading.value = false;
    }
}

async function loadMe() {
    if (!auth.me) {
        try { auth.me = (await get('/me')).data; } catch { /* 401이면 api.js가 처리 */ }
    }
}

function openPlaza(id) {
    router.push({ name: 'plaza', params: { id } });
}

function onCreated(plaza) {
    creating.value = false;
    openPlaza(plaza.id);
}

watch(sort, () => load());

onMounted(() => {
    loadMe();
    load();
    timer = setInterval(() => load({ silent: true }), 20_000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <div class="main">
        <!-- 상단 -->
        <div class="top">
            <button class="loc" :aria-label="`내 위치: ${location.label}`" @click="location.fallback && showToast('위치 권한이 없어 기본 위치(역삼역)로 보여주고 있어요')">
                {{ location.label }}
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
            </button>
            <button class="me" aria-label="내 아바타 (MY)" @click="router.push({ name: 'my' })">
                <AvatarFace :parts="auth.me?.avatar" :size="36" />
            </button>
        </div>

        <div class="body scroll">
            <h1 class="title" style="font-size: 28px; margin-top: 4px">오늘은 어느 광장에<br>놀러갈까?</h1>

            <!-- 정렬 탭 -->
            <div class="tabs" role="tablist" aria-label="정렬">
                <button
                    v-for="s in SORTS" :key="s.key" role="tab"
                    :aria-selected="sort === s.key" :class="{ on: sort === s.key }"
                    @click="sort = s.key"
                >{{ s.label }}</button>
            </div>

            <!-- 광장 미리보기 그리드 -->
            <div v-if="loading" class="grid" aria-busy="true">
                <div v-for="i in 4" :key="i" class="tile skeleton" />
            </div>

            <div v-else-if="tiles.length" class="grid">
                <button v-for="t in tiles" :key="t.id" class="tile" @click="openPlaza(t.id)">
                    <span class="stage" aria-hidden="true" :style="{ background: t.ground }">
                        <span class="pad" />
                        <span v-for="(a, j) in t.avatars" :key="j" class="av" :style="{ left: `${a.x}px`, top: `${a.y}px` }">
                            <AvatarFace :parts="a.parts" :size="30" />
                        </span>
                        <span v-if="t.last_message" class="bubble" :style="{ left: `${t.bubbleX}px` }">{{ t.last_message }}</span>
                        <span class="live"><i />{{ t.live_count }}명</span>
                    </span>
                    <span class="info">
                        <span class="name">{{ t.name }}</span>
                        <span class="meta">{{ t.meta }}</span>
                    </span>
                </button>
            </div>

            <div v-else class="empty">이 근처엔 아직 광장이 없어요.<br>첫 광장을 만들어보세요!</div>
        </div>

        <!-- 광장 만들기 -->
        <button class="fab" aria-label="광장 만들기" @click="creating = true">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
        </button>

        <TabBar active="plaza" />
        <CreatePlazaSheet v-if="creating" @close="creating = false" @created="onCreated" />
    </div>
</template>

<style scoped>
.main { height: 100%; display: flex; flex-direction: column; position: relative; }
.top { display: flex; justify-content: space-between; align-items: center; padding: 20px 20px 4px; }
.loc { display: flex; align-items: center; gap: 4px; background: none; border: none; padding: 10px 4px; font-size: 16px; font-weight: 700; color: var(--c-ink); }
.me { width: 44px; height: 44px; border-radius: 22px; border: 2px solid #fff; background: #E6E1FF; display: flex; align-items: center; justify-content: center; padding: 0; box-shadow: 0 2px 8px rgba(31,27,58,.12); }

.body { flex: 1; min-height: 0; padding: 0 20px 120px; display: flex; flex-direction: column; gap: 16px; }

.tabs { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 4px; background: #ECE8FF; padding: 4px; border-radius: 14px; flex-shrink: 0; }
.tabs button { height: 40px; border: none; border-radius: 11px; background: transparent; color: var(--c-muted); font-size: 14px; font-weight: 500; }
.tabs button.on { background: var(--c-surface); color: var(--c-ink); font-weight: 700; box-shadow: 0 1px 4px rgba(31,27,58,.10); }

.grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.tile { text-align: left; border: none; background: var(--c-surface); border-radius: 22px; padding: 8px 8px 12px; display: flex; flex-direction: column; gap: 10px; box-shadow: var(--shadow-card); min-width: 0; }
.tile.skeleton { height: 178px; background: linear-gradient(90deg, #fff 0%, #F1EEFF 50%, #fff 100%); background-size: 200% 100%; animation: shimmer 1.2s infinite; }
@keyframes shimmer { to { background-position: -200% 0; } }

.stage { position: relative; display: block; height: 118px; border-radius: 16px; overflow: hidden; }
.pad { position: absolute; left: 50%; top: 50%; width: 90px; height: 40px; margin: -20px 0 0 -45px; border-radius: 50%; background: rgba(255,255,255,.55); }
.av { position: absolute; display: flex; }
.bubble { position: absolute; top: 10px; max-width: calc(100% - 24px); background: #fff; border-radius: 10px; padding: 3px 8px; font-size: 11px; font-weight: 700; color: var(--c-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.live { position: absolute; right: 8px; bottom: 8px; display: flex; align-items: center; gap: 4px; background: rgba(31,27,58,.75); color: #fff; border-radius: 10px; padding: 2px 8px; font-size: 11px; font-weight: 700; }
.live i { width: 6px; height: 6px; border-radius: 3px; background: #7CF2B0; }

.info { display: flex; flex-direction: column; gap: 3px; padding: 0 4px; min-width: 0; }
.name { font-size: 14px; font-weight: 700; color: var(--c-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.meta { font-size: 12px; color: var(--c-muted); }

.empty { background: var(--c-surface); border-radius: 20px; padding: 28px 20px; text-align: center; font-size: 14px; color: var(--c-muted); line-height: 1.6; }

.fab { position: absolute; right: 20px; bottom: 96px; width: 60px; height: 60px; border-radius: 30px; border: none; background: var(--c-primary); display: flex; align-items: center; justify-content: center; box-shadow: 0 6px 16px rgba(108,92,231,.40); z-index: 5; }
</style>
