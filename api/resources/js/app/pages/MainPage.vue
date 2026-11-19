<script setup>
/**
 * 메인 — 상시 광장 + 주민 광장 (디자인: MainGrid.dc.html)
 * - 최상단 크게: 우리 동네 상시 광장 → GET /town (접속자·풍경 미리보기·말풍선·여기 있는 친구)
 * - 아래: 주민이 만든 광장 타일
 * - 정렬 탭: 가까운 순 / 북적이는 순 / 새로 생긴  → GET /plazas?sort=
 * - 검색: 검색창(이름·설명·카테고리, 띄어쓰기 단어 모두 포함) + 카테고리 칩 → GET /plazas?q=&category=
 *   검색 상태는 주소(/?q=&category=)에 둔다 → 광장에 들어갔다 뒤로 와도 결과가 그대로
 *   검색하면 전국 광장이 보이지만, 들어가는 건 5km 안만 (카드에 🔒, 최종 판단은 서버)
 * - 위치 권한이 없으면: 기본 위치(역삼) 좌표를 서버에 보내지 않는다 → 맨 위는 자유광장, 주변 목록 대신 안내
 *   (기본 위치를 인정하면 위치만 끄면 역삼 근처 광장에 들어갈 수 있어서)
 * - 타일: 광장 안 접속자 아바타 + 방금 한 말(말풍선) + 접속자 수
 * - 접속자 수가 계속 바뀌므로 20초마다 새로고침
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AvatarFace from '../components/AvatarFace.vue';
import CreatePlazaSheet from '../components/CreatePlazaSheet.vue';
import TabBar from '../components/TabBar.vue';
import { ApiError, get } from '../lib/api';
import { auth } from '../lib/auth';
import { CATEGORY_GROUND } from '../lib/avatar';
import { getLocation } from '../lib/location';
import { notify } from '../lib/notify';
import { showToast } from '../lib/toast';

const router = useRouter();
const route = useRoute();

const SORTS = [
    { key: 'near', label: '가까운 순' },
    { key: 'busy', label: '북적이는 순' },
    { key: 'new', label: '새로 생긴' },
];
// 상시 광장 카드 풍경의 아바타 자리 9개 (디자인 시안 배치 그대로)
const TOWN_SLOTS = [
    { x: 24, y: 70 }, { x: 62, y: 50 }, { x: 98, y: 86 }, { x: 132, y: 56 }, { x: 170, y: 92 },
    { x: 206, y: 62 }, { x: 244, y: 88 }, { x: 280, y: 58 }, { x: 300, y: 104 },
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
const town = ref(null);               // 우리 동네 상시 광장 (동네를 못 찾으면 null → 카드 숨김)
const free = ref(null);               // 전국 자유광장 (위치 없이 누구나)
let timer = null;

// 위치 권한이 없거나 실패해 기본 위치로 대체된 상태
const noLocation = computed(() => location.value.fallback === true);
// 맨 위 큰 카드: 위치가 있으면 우리 동네 광장, 없으면 자유광장
const card = computed(() => (noLocation.value ? free.value : town.value));

// ── 검색 ──
const categories = ref([]);                              // [{code, label}] 칩 (못 불러오면 칩만 안 보임)
const q = ref(typeof route.query.q === 'string' ? route.query.q : '');
const category = ref(typeof route.query.category === 'string' ? route.query.category : '');
const searching = computed(() => q.value.trim() !== '' || category.value !== '');
const categoryLabel = computed(() => categories.value.find((c) => c.code === category.value)?.label ?? '');
const emptyText = computed(() => {
    const what = [q.value.trim() && `'${q.value.trim()}'`, categoryLabel.value && `${categoryLabel.value} 카테고리`].filter(Boolean).join(' · ');
    return `${what} 광장을 찾지 못했어요.`;
});
let typingTimer = null;
let seq = 0; // 요청 순번: 늦게 도착한 옛 검색 결과가 새 결과를 덮어쓰지 않게

const tiles = computed(() => plazas.value.map((p, i) => ({
    ...p,
    ground: CATEGORY_GROUND[p.category] ?? '#E9E4FF',
    avatars: p.preview_avatars.slice(0, SLOTS.length).map((parts, j) => ({ parts, ...SLOTS[j] })),
    // '대화 중' 말풍선 위치를 타일마다 조금씩 다르게 (디자인처럼 자연스럽게)
    bubbleX: [14, 60, 30, 50, 20, 56][i % 6],
    meta: [p.category_label, p.distance_label].filter(Boolean).join(' · '),
    // 들어갈 수 없는 광장: 위치 권한 없음 또는 5km 밖 (서버 can_enter=false)
    locked: noLocation.value || p.can_enter === false,
})));

const townAvatars = computed(() => (card.value?.preview_avatars ?? [])
    .slice(0, TOWN_SLOTS.length).map((parts, i) => ({ parts, ...TOWN_SLOTS[i] })));


async function load({ silent = false } = {}) {
    const my = ++seq;
    if (!silent) loading.value = true;
    try {
        const loc = await getLocation();
        const hasLoc = !loc.fallback;
        location.value = hasLoc ? loc : { ...loc, label: '위치 꺼짐' };
        const params = new URLSearchParams({ sort: sort.value });
        if (hasLoc) { params.set('lat', loc.lat); params.set('lng', loc.lng); } // 기본 위치 좌표는 안 보냄
        if (q.value.trim()) params.set('q', q.value.trim().slice(0, 30)); // 서버 검증 최대 30자
        if (category.value) params.set('category', category.value);
        // 상시 광장 카드·자유광장·주민 광장 목록을 함께 (카드 실패는 목록에 영향 없게 따로 처리)
        const [res, mine, open] = await Promise.all([
            get(`/plazas?${params}`),
            hasLoc ? get(`/town?lat=${loc.lat}&lng=${loc.lng}`).catch(() => ({ data: town.value })) : { data: null },
            get('/free-plaza').catch(() => ({ data: free.value })),
        ]);
        if (my !== seq) return; // 그 사이 검색 조건이 바뀌어 새 요청이 나감 → 이 결과는 버림
        plazas.value = res.data;
        town.value = mine.data;
        free.value = open.data;
        // 위치 이름: 동네 이름(역삼동)을 알면 그걸로
        if (mine.data?.region_name) location.value = { ...loc, label: mine.data.region_name };
    } catch (e) {
        if (!silent && my === seq) showToast(e instanceof ApiError ? e.message : '광장 목록을 불러오지 못했어요');
    } finally {
        if (my === seq) loading.value = false;
    }
}

/** 검색 조건을 주소에 반영. replace: 글자마다 방문 기록이 쌓이지 않게 */
function syncQuery() {
    const query = {};
    if (q.value.trim()) query.q = q.value.trim();
    if (category.value) query.category = category.value;
    router.replace({ query });
}

function search() {
    clearTimeout(typingTimer);
    syncQuery();
    load();
}

// 입력을 0.3초 멈추면 검색 (글자마다 요청하지 않게)
function onTyping() {
    clearTimeout(typingTimer);
    typingTimer = setTimeout(search, 300);
}

function onSubmit(e) {
    search();
    e.target.querySelector('input')?.blur(); // 휴대폰 키보드 내리기
}

function clearQuery() {
    q.value = '';
    search();
}

function pickCategory(code) {
    category.value = category.value === code ? '' : code; // 같은 칩을 다시 누르면 해제
    search();
}

async function loadMe() {
    if (!auth.me) {
        try { auth.me = (await get('/me')).data; } catch { /* 401이면 api.js가 처리 */ }
    }
}

function openTown(t = card.value) {
    if (t) router.push({ name: 'town', params: { id: t.id } });
}

function openPlaza(id) {
    router.push({ name: 'plaza', params: { id } });
}

/** 타일 누르기: 잠긴 광장은 들어가지 않고 이유만 안내 (서버도 같은 조건으로 막음) */
function openTile(t) {
    if (!t.locked) return openPlaza(t.id);
    showToast(noLocation.value
        ? '위치 권한을 켜야 들어갈 수 있어요. 자유광장은 누구나 들어갈 수 있어요'
        : '내 위치에서 5km 안에 있는 광장만 들어갈 수 있어요', 3500);
}

/** 광장 만들기: 위치 없이 만들면 기본 위치(역삼)에 생겨 버리므로 막는다 */
function startCreate() {
    if (noLocation.value) return showToast('위치 권한을 켜야 광장을 만들 수 있어요', 3000);
    creating.value = true;
}

function onLocClick() {
    if (noLocation.value) showToast('위치 권한을 켜면 동네 광장과 주변 광장에 들어갈 수 있어요. 브라우저 설정에서 위치를 허용해 주세요', 4000);
}

function onCreated(plaza) {
    creating.value = false;
    openPlaza(plaza.id);
}

watch(sort, () => load());

// 주소가 바깥에서 바뀐 경우(탭바로 메인을 다시 누름 등) 검색창도 맞춘다
watch(() => [route.query.q, route.query.category], ([nq, nc]) => {
    const nextQ = typeof nq === 'string' ? nq : '';
    const nextC = typeof nc === 'string' ? nc : '';
    if (nextQ === q.value.trim() && nextC === category.value) return; // 내가 바꾼 주소
    q.value = nextQ;
    category.value = nextC;
    load();
});

onMounted(() => {
    loadMe();
    load();
    get('/meta/categories').then((r) => { categories.value = r.data; }).catch(() => {});
    timer = setInterval(() => load({ silent: true }), 20_000);
});
onBeforeUnmount(() => {
    clearInterval(timer);
    clearTimeout(typingTimer);
});
</script>

<template>
    <div class="main">
        <!-- 상단 -->
        <div class="top">
            <button class="loc" :aria-label="`내 위치: ${location.label}`" @click="onLocClick">
                {{ location.label }}
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
            </button>
            <div class="right">
                <button class="bell" :aria-label="notify.unread ? `알림 ${notify.unread}개 안 읽음` : '알림'" @click="router.push({ name: 'notifications' })">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9" /><path d="M13.7 21a2 2 0 01-3.4 0" /></svg>
                    <span v-if="notify.unread" class="badge">{{ notify.unread > 99 ? '99+' : notify.unread }}</span>
                </button>
                <button class="me" aria-label="내 아바타 (MY)" @click="router.push({ name: 'my' })">
                    <AvatarFace :parts="auth.me?.avatar" :size="36" />
                </button>
            </div>
        </div>

        <div class="body scroll">
            <!-- ===== 우리 동네 상시 광장 (메인 최상단, 크게) ===== -->
            <!-- 검색 중에는 접어서 결과가 바로 보이게 -->
            <div v-if="!searching && loading && !card" class="town skeleton" aria-busy="true" />
            <button v-else-if="!searching && card" type="button" class="town" :aria-label="`${card.name} 입장하기, ${card.live_count}명 접속중`" @click="openTown()">
                <span class="town-top">
                    <span class="town-badge">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z" /><circle cx="12" cy="9.5" r="2.5" /></svg>
                        {{ card.is_free ? '전국 자유광장' : '우리 동네 상시 광장' }}
                    </span>
                    <span class="town-live"><i />{{ card.live_count }}명 접속중</span>
                </span>
                <span class="town-head">
                    <span class="title town-name">{{ card.name }}</span>
                    <span class="town-desc">{{ card.description }}</span>
                </span>

                <!-- 광장 풍경 미리보기 -->
                <span class="town-stage" aria-hidden="true">
                    <span class="ts-ground" />
                    <span class="ts-pad" />
                    <span class="ts-tree t1" />
                    <span class="ts-tree t2" />
                    <span v-for="(a, i) in townAvatars" :key="i" class="ts-av" :style="{ left: `${a.x}px`, top: `${a.y}px` }">
                        <AvatarFace :parts="a.parts" :size="32" />
                    </span>
                    <!-- 대화 내용은 밖에 안 보임: 최근 10분 대화가 있으면 '…' 말풍선만 -->
                    <span v-if="card.chatting" class="ts-bubble typing" style="left: 120px; top: 24px" aria-label="지금 대화 중"><i /><i /><i /></span>
                </span>

                <span class="town-foot">
                    <span v-if="card.friends.count" class="town-friends">
                        <span class="tf-avs">
                            <span v-for="(f, i) in card.friends.avatars.slice(0, 2)" :key="i" class="tf-av"><AvatarFace :parts="f" :size="22" variant="mini" /></span>
                        </span>
                        <span><b>친구 {{ card.friends.count }}명</b>이 여기 있어요</span>
                    </span>
                    <span v-else class="town-friends">{{ card.live_count ? (card.is_free ? '지금 전국에서 모여 있어요' : '지금 동네 사람들이 모여 있어요') : '첫 번째로 들어가 볼까요?' }}</span>
                    <span class="town-enter">입장하기</span>
                </span>
            </button>

            <!-- 위치가 있으면 자유광장은 작은 입구로 -->
            <button v-if="!searching && !noLocation && free" type="button" class="free-entry" @click="openTown(free)">
                <span class="fe-icon" aria-hidden="true">🌏</span>
                <span class="fe-text"><b>자유광장</b> · 전국 누구나</span>
                <span class="fe-live">{{ free.live_count }}명</span>
            </button>
            <!-- 위치가 없으면 왜 동네·주변 광장이 없는지 안내 -->
            <button v-if="noLocation" type="button" class="loc-notice" @click="onLocClick">
                📍 위치 권한을 켜면 <b>동네 광장</b>과 <b>주변 광장</b>에 들어갈 수 있어요
            </button>

            <!-- 주민이 만든 광장 -->
            <div class="section-head">
                <h2>{{ searching ? '광장 찾기' : '주민이 만든 광장' }}</h2>
                <span>{{ searching && !loading ? `${tiles.length}개` : '관심사별 모임' }}</span>
            </div>

            <!-- 검색창 -->
            <form class="search" role="search" @submit.prevent="onSubmit">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6B6785" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" /></svg>
                <label class="sr-only" for="plaza-q">광장 검색</label>
                <input
                    id="plaza-q" v-model="q" type="search" maxlength="30" enterkeyhint="search" autocomplete="off"
                    placeholder="광장 이름이나 관심사 (예: 강남 러닝)" @input="onTyping"
                >
                <button v-if="q" type="button" class="clear" aria-label="검색어 지우기" @click="clearQuery">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="3" stroke-linecap="round" aria-hidden="true"><path d="M7 7l10 10M17 7L7 17" /></svg>
                </button>
            </form>

            <!-- 카테고리 칩 -->
            <div v-if="categories.length" class="chips" role="group" aria-label="카테고리">
                <button type="button" :aria-pressed="!category" :class="{ on: !category }" @click="pickCategory('')">전체</button>
                <button
                    v-for="c in categories" :key="c.code" type="button"
                    :aria-pressed="category === c.code" :class="{ on: category === c.code }"
                    @click="pickCategory(c.code)"
                >{{ c.label }}</button>
            </div>

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
                <button
                    v-for="t in tiles" :key="t.id" class="tile" :class="{ locked: t.locked }"
                    :aria-label="t.locked ? `${t.name}, 들어갈 수 없음` : undefined" @click="openTile(t)"
                >
                    <span class="stage" aria-hidden="true" :style="{ background: t.ground }">
                        <span class="pad" />
                        <span v-for="(a, j) in t.avatars" :key="j" class="av" :style="{ left: `${a.x}px`, top: `${a.y}px` }">
                            <AvatarFace :parts="a.parts" :size="30" />
                        </span>
                        <!-- 대화 내용은 밖에 안 보임(참여자만 읽음): 최근 10분 대화가 있으면 '…' 말풍선만 -->
                        <span v-if="t.chatting" class="bubble typing" :style="{ left: `${t.bubbleX}px` }" aria-label="지금 대화 중"><i /><i /><i /></span>
                        <span class="live"><i />{{ t.live_count }}명</span>
                        <span v-if="t.locked" class="lock">🔒 {{ noLocation ? '위치 필요' : '5km 밖' }}</span>
                    </span>
                    <span class="info">
                        <span class="name">{{ t.name }}</span>
                        <span class="meta">{{ t.meta }}</span>
                    </span>
                </button>
            </div>

            <div v-else-if="searching" class="empty">
                {{ emptyText }}<br>찾는 광장을 직접 열어볼까요?
                <button type="button" class="make" @click="startCreate">광장 만들기</button>
            </div>
            <div v-else-if="noLocation" class="empty">위치 권한이 없어 주변 광장을 보여드릴 수 없어요.<br>위에서 검색하면 전국 광장을 둘러볼 수 있어요.</div>
            <div v-else class="empty">이 근처엔 아직 광장이 없어요.<br>첫 광장을 만들어보세요!</div>
        </div>

        <!-- 광장 만들기 -->
        <button class="fab" aria-label="광장 만들기" @click="startCreate">
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
.right { display: flex; align-items: center; gap: 6px; }
.bell { position: relative; width: 44px; height: 44px; border: none; background: none; display: flex; align-items: center; justify-content: center; padding: 0; }
.bell .badge { position: absolute; top: 4px; right: 2px; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9px; background: #F0506E; color: #fff; font-size: 11px; font-weight: 700; line-height: 18px; text-align: center; border: 2px solid var(--c-bg); box-sizing: content-box; }
.me { width: 44px; height: 44px; border-radius: 22px; border: 2px solid #fff; background: #E6E1FF; display: flex; align-items: center; justify-content: center; padding: 0; box-shadow: 0 2px 8px rgba(31,27,58,.12); }

.body { flex: 1; min-height: 0; padding: 0 20px 120px; display: flex; flex-direction: column; gap: 16px; }

.body > .town { margin-top: 4px; }
.town { text-align: left; border: none; width: 100%; background: var(--c-primary); border-radius: 28px; padding: 16px; display: flex; flex-direction: column; gap: 12px; color: #fff; box-shadow: 0 8px 24px rgba(108,92,231,.30); }
.town.skeleton { height: 312px; background: linear-gradient(90deg, #8E80F0 0%, #A79CF5 50%, #8E80F0 100%); background-size: 200% 100%; animation: shimmer 1.2s infinite; box-shadow: none; }
.town-top { display: flex; justify-content: space-between; align-items: center; }
.town-badge { display: inline-flex; align-items: center; gap: 5px; background: rgba(255,255,255,.18); border-radius: 10px; padding: 4px 10px; font-size: 12px; font-weight: 700; }
.town-live { display: inline-flex; align-items: center; gap: 5px; font-size: 13px; font-weight: 700; }
.town-live i { width: 8px; height: 8px; border-radius: 4px; background: #7CF2B0; }
.town-head { display: flex; flex-direction: column; gap: 2px; }
.town-name { font-size: 30px; line-height: 1.2; }
.town-desc { font-size: 13px; color: #E6E1FF; }
.town-stage { position: relative; display: block; height: 150px; border-radius: 20px; background: #8E80F0; overflow: hidden; }
.ts-ground { position: absolute; left: 30px; top: 40px; width: 290px; height: 100px; border-radius: 50%; background: rgba(255,255,255,.22); }
.ts-pad { position: absolute; left: 140px; top: 74px; width: 70px; height: 32px; border-radius: 50%; background: rgba(255,255,255,.30); }
.ts-tree { position: absolute; border-radius: 50%; background: #A8E6CF; }
.ts-tree.t1 { left: 10px; top: 8px; width: 34px; height: 34px; }
.ts-tree.t2 { right: 12px; top: 96px; width: 30px; height: 30px; }
.ts-av { position: absolute; display: flex; }
.ts-bubble { position: absolute; max-width: 140px; background: #fff; color: var(--c-ink); border-radius: 10px; padding: 3px 8px; font-size: 11px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.town-foot { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.town-friends { display: flex; align-items: center; gap: 8px; font-size: 13px; min-width: 0; }
.tf-avs { display: flex; }
.tf-av { width: 26px; height: 26px; border-radius: 13px; border: 2px solid var(--c-primary); display: flex; align-items: center; justify-content: center; background: var(--c-primary); }
.tf-av + .tf-av { margin-left: -8px; }
.town-enter { flex-shrink: 0; background: #fff; color: var(--c-primary-ink); font-size: 15px; font-weight: 700; padding: 11px 18px; border-radius: 16px; }

.section-head { display: flex; justify-content: space-between; align-items: baseline; margin-top: 4px; }
.section-head h2 { margin: 0; font-size: 15px; font-weight: 700; }
.section-head span { font-size: 12px; color: var(--c-muted); }

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
.typing { display: inline-flex; align-items: center; gap: 3px; padding: 6px 9px; }
.typing i { width: 5px; height: 5px; border-radius: 50%; background: var(--c-muted); animation: typing-dot 1.2s infinite ease-in-out; }
.typing i:nth-child(2) { animation-delay: .15s; }
.typing i:nth-child(3) { animation-delay: .3s; }
@keyframes typing-dot { 0%, 60%, 100% { opacity: .35; transform: translateY(0); } 30% { opacity: 1; transform: translateY(-2px); } }
@media (prefers-reduced-motion: reduce) { .typing i { animation: none; } }
.bubble { position: absolute; top: 10px; max-width: calc(100% - 24px); background: #fff; border-radius: 10px; padding: 3px 8px; font-size: 11px; font-weight: 700; color: var(--c-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.live { position: absolute; right: 8px; bottom: 8px; display: flex; align-items: center; gap: 4px; background: rgba(31,27,58,.75); color: #fff; border-radius: 10px; padding: 2px 8px; font-size: 11px; font-weight: 700; }
.live i { width: 6px; height: 6px; border-radius: 3px; background: #7CF2B0; }

.info { display: flex; flex-direction: column; gap: 3px; padding: 0 4px; min-width: 0; }
.name { font-size: 14px; font-weight: 700; color: var(--c-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.meta { font-size: 12px; color: var(--c-muted); }

.search { display: flex; align-items: center; gap: 8px; height: 46px; padding: 0 8px 0 14px; background: var(--c-surface); border: 1.5px solid var(--c-line); border-radius: 16px; flex-shrink: 0; }
.search:focus-within { border-color: var(--c-primary); }
.search input { flex: 1; min-width: 0; border: none; outline: none; background: transparent; font-size: 15px; color: var(--c-ink); }
.search input::-webkit-search-cancel-button { display: none; } /* 브라우저 기본 X 대신 아래 버튼 하나만 */
.clear { width: 28px; height: 28px; border: none; border-radius: 14px; background: #B8B3D1; display: flex; align-items: center; justify-content: center; padding: 0; flex-shrink: 0; }
.chips { display: flex; gap: 6px; overflow-x: auto; scrollbar-width: none; flex-shrink: 0; margin: -4px -20px 0; padding: 0 20px; }
.chips::-webkit-scrollbar { display: none; }
.chips button { flex-shrink: 0; height: 34px; padding: 0 14px; border: 1.5px solid var(--c-line); border-radius: 17px; background: var(--c-surface); color: var(--c-ink-2); font-size: 13px; font-weight: 700; }
.chips button.on { background: var(--c-primary); border-color: var(--c-primary); color: #fff; }
.make { display: block; margin: 14px auto 0; height: 44px; padding: 0 20px; border: none; border-radius: 14px; background: var(--c-primary); color: #fff; font-size: 14px; font-weight: 700; }

.free-entry { display: flex; align-items: center; gap: 10px; width: 100%; padding: 12px 16px; border: 1.5px solid var(--c-line); border-radius: 18px; background: var(--c-surface); text-align: left; font-size: 14px; color: var(--c-ink); }
.fe-icon { font-size: 20px; }
.fe-text { flex: 1; min-width: 0; }
.fe-live { font-size: 13px; font-weight: 700; color: var(--c-primary-ink); }
.loc-notice { width: 100%; padding: 12px 16px; border: none; border-radius: 16px; background: #FFF4D6; color: var(--c-ink); font-size: 13px; line-height: 1.5; text-align: left; }
/* 들어갈 수 없는 광장: 흐리게 + 자물쇠 */
.tile.locked .stage, .tile.locked .info { opacity: .5; }
.lock { position: absolute; left: 8px; bottom: 8px; background: rgba(31,27,58,.75); color: #fff; border-radius: 10px; padding: 2px 8px; font-size: 11px; font-weight: 700; }

.empty { background: var(--c-surface); border-radius: 20px; padding: 28px 20px; text-align: center; font-size: 14px; color: var(--c-muted); line-height: 1.6; }

.fab { position: absolute; right: 20px; bottom: 96px; width: 60px; height: 60px; border-radius: 30px; border: none; background: var(--c-primary); display: flex; align-items: center; justify-content: center; box-shadow: 0 6px 16px rgba(108,92,231,.40); z-index: 5; }
</style>
