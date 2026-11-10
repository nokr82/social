<script setup>
/**
 * 친구 요청 관리 (디자인: FriendRequests.dc.html)
 * 받은 요청 카드 상태: pending → accepted(정보가 공개되는 순간) / rejected(되돌리기 가능)
 * 보낸 요청: 수락 기다리는 중 + 요청 취소 (거절돼도 여기선 계속 대기 중으로 보임)
 */
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import AvatarFace from '../components/AvatarFace.vue';
import HeartIcon from '../components/HeartIcon.vue';
import LockIcon from '../components/LockIcon.vue';
import { ApiError, del, get, post } from '../lib/api';
import { comingSoon, showToast } from '../lib/toast';

const router = useRouter();

const tab = ref('received');
const items = ref([]);
const counts = ref({ received: 0, sent: 0 });
const loading = ref(true);
const busy = ref(new Set());

// 받은 요청 중 화면에서 처리한 것은 목록에서 빼지 않고 상태만 바꿔 보여준다 (디자인)
const pendingCount = computed(() => items.value.filter((r) => !r.state || r.state === 'pending').length);

async function load() {
    loading.value = true;
    try {
        const res = await get(`/friend-requests?type=${tab.value}`);
        items.value = res.data.map((r) => ({ ...r, state: 'pending' }));
        counts.value = res.counts;
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '불러오지 못했어요');
    } finally {
        loading.value = false;
    }
}

async function act(r, fn) {
    if (busy.value.has(r.id)) return;
    busy.value.add(r.id);
    try {
        await fn();
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '잠시 후 다시 시도해 주세요');
    } finally {
        busy.value.delete(r.id);
    }
}

const accept = (r) => act(r, async () => {
    r.revealed = (await post(`/friend-requests/${r.id}/accept`)).data; // 성별·나이·비공개 사진
    r.state = 'accepted';
});
const reject = (r) => act(r, async () => {
    await post(`/friend-requests/${r.id}/reject`);
    r.state = 'rejected';
});
const undo = (r) => act(r, async () => {
    await post(`/friend-requests/${r.id}/undo`);
    r.state = 'pending';
});
const cancel = (r) => act(r, async () => {
    await del(`/friend-requests/${r.id}`);
    items.value = items.value.filter((x) => x.id !== r.id);
    counts.value.sent--;
});

function ago(iso) {
    const m = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
    if (m < 1) return '방금';
    if (m < 60) return `${m}분 전`;
    if (m < 60 * 24) return `${Math.floor(m / 60)}시간 전`;
    return Math.floor(m / 1440) === 1 ? '어제' : `${Math.floor(m / 1440)}일 전`;
}

watch(tab, load);
onMounted(load);
</script>

<template>
    <div class="fr">
        <div class="head">
            <button class="icon" aria-label="뒤로가기" @click="router.back()">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
            </button>
            <h1 class="title" style="font-size: 22px">친구 요청</h1>
        </div>

        <div class="tabs" role="tablist" aria-label="요청 종류">
            <button role="tab" :aria-selected="tab === 'received'" :class="{ on: tab === 'received' }" @click="tab = 'received'">
                받은 요청 {{ tab === 'received' ? pendingCount : counts.received }}
            </button>
            <button role="tab" :aria-selected="tab === 'sent'" :class="{ on: tab === 'sent' }" @click="tab = 'sent'">보낸 요청 {{ counts.sent }}</button>
        </div>

        <div class="list scroll">
            <p v-if="!loading && !items.length" class="empty">{{ tab === 'received' ? '받은 친구 요청이 없어요' : '보낸 친구 요청이 없어요' }}</p>

            <!-- 받은 요청 -->
            <template v-if="tab === 'received'">
                <div v-for="r in items" :key="r.id">
                    <!-- 대기 중 -->
                    <div v-if="r.state === 'pending'" class="card">
                        <div class="who">
                            <span class="av"><AvatarFace :parts="r.user.avatar" :size="42" /></span>
                            <span class="col">
                                <span class="name-row"><b>{{ r.user.nickname }}</b><span class="heart"><HeartIcon :size="12" />{{ r.public_hearts }}</span></span>
                                <small>{{ r.plaza_name ? `${r.plaza_name}에서 · ` : '' }}{{ ago(r.created_at) }}</small>
                            </span>
                        </div>
                        <span v-if="r.message" class="msg">{{ r.message }}</span>
                        <div class="photos">
                            <button v-for="p in r.photos" :key="p.id" class="ph" :aria-label="`${r.user.nickname} 공개 사진`" @click="router.push({ name: 'profile', params: { id: r.user.id } })">
                                <img :src="p.url" alt="" loading="lazy">
                            </button>
                            <div class="ph lock"><LockIcon :size="16" />비공개 {{ r.private_count }}</div>
                        </div>
                        <div class="hint"><LockIcon :size="14" />수락하면 서로의 비공개 사진·성별·나이가 공개돼요</div>
                        <div class="actions">
                            <button class="ghost" :disabled="busy.has(r.id)" @click="reject(r)">거절</button>
                            <button class="solid" :disabled="busy.has(r.id)" @click="accept(r)">수락하기</button>
                        </div>
                    </div>

                    <!-- 수락함: 정보가 공개되는 순간 -->
                    <div v-else-if="r.state === 'accepted'" class="card accepted">
                        <div class="banner">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5L20 7" /></svg>
                            {{ r.user.nickname }}님과 친구가 됐어요!
                        </div>
                        <div class="inner">
                            <div class="who">
                                <AvatarFace :parts="r.user.avatar" :size="44" />
                                <b class="grow">{{ r.user.nickname }}</b>
                                <span class="pill">{{ r.revealed.gender === 'F' ? '여' : '남' }}</span>
                                <span class="pill">{{ r.revealed.age }}세</span>
                            </div>
                            <template v-if="r.revealed.private_photos.length">
                                <span class="new">새로 공개된 사진 {{ r.revealed.private_photos.length }}장</span>
                                <div class="grid3">
                                    <div v-for="p in r.revealed.private_photos" :key="p.id" class="ph big"><img :src="p.url" alt="새로 공개된 사진" loading="lazy"></div>
                                </div>
                            </template>
                            <div class="actions">
                                <button class="line" @click="router.push({ name: 'profile', params: { id: r.user.id } })">프로필 보기</button>
                                <button class="solid" @click="comingSoon('1:1 대화')">대화하기</button>
                            </div>
                        </div>
                    </div>

                    <!-- 거절함: 상대에게는 알리지 않음 -->
                    <div v-else class="card rejected">
                        <span class="col grow">
                            <b>{{ r.user.nickname }}님의 요청을 거절했어요</b>
                            <small>상대에게는 알리지 않아요</small>
                        </span>
                        <button class="undo" :disabled="busy.has(r.id)" @click="undo(r)">되돌리기</button>
                    </div>
                </div>
            </template>

            <!-- 보낸 요청 -->
            <template v-else>
                <div v-for="r in items" :key="r.id" class="card sent">
                    <AvatarFace :parts="r.user.avatar" :size="44" />
                    <span class="col grow">
                        <b>{{ r.user.nickname }}</b>
                        <small>수락 기다리는 중 · {{ ago(r.created_at) }}</small>
                    </span>
                    <button class="cancel" :disabled="busy.has(r.id)" @click="cancel(r)">요청 취소</button>
                </div>
                <p v-if="items.length" class="foot">7일 동안 응답이 없으면 요청이 자동으로 사라져요</p>
            </template>
        </div>
    </div>
</template>

<style scoped>
.fr { height: 100%; display: flex; flex-direction: column; }
.head { display: flex; align-items: center; gap: 4px; padding: 14px 12px 6px 8px; }
.icon { width: 44px; height: 44px; border: none; background: none; display: flex; align-items: center; justify-content: center; }
.tabs { margin: 4px 20px 0; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 4px; background: #ECE8FF; padding: 4px; border-radius: 14px; }
.tabs button { height: 40px; border: none; border-radius: 11px; background: transparent; color: var(--c-muted); font-size: 14px; font-weight: 500; }
.tabs button.on { background: var(--c-surface); color: var(--c-ink); font-weight: 700; box-shadow: 0 1px 4px rgba(31,27,58,.10); }
.list { flex: 1; min-height: 0; padding: 16px 20px 32px; display: flex; flex-direction: column; gap: 14px; }
.empty { margin: 40px 0 0; text-align: center; font-size: 14px; color: var(--c-muted); }

.card { background: var(--c-surface); border-radius: 22px; padding: 14px; display: flex; flex-direction: column; gap: 12px; box-shadow: 0 2px 10px rgba(108,92,231,.07); }
.who { display: flex; align-items: center; gap: 10px; }
.av { width: 48px; height: 48px; border-radius: 24px; background: var(--c-primary-soft); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.col { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.col small { font-size: 12px; color: var(--c-muted); }
.grow { flex: 1; }
.name-row { display: flex; align-items: center; gap: 6px; font-size: 15px; }
.heart { display: inline-flex; align-items: center; gap: 3px; font-size: 12px; font-weight: 700; color: #D63E5C; }
.msg { background: var(--c-primary-soft); border-radius: 4px 14px 14px 14px; padding: 8px 12px; font-size: 14px; align-self: flex-start; }
.photos { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; }
.ph { height: 66px; border-radius: 12px; overflow: hidden; background: #E6E1FF; border: none; padding: 0; }
.ph img { width: 100%; height: 100%; object-fit: cover; display: block; }
.ph.lock { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; font-size: 11px; font-weight: 700; color: var(--c-primary-deep); }
.ph.big { height: 96px; border-radius: 14px; }
.hint { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--c-primary-deep); background: var(--c-bg); border-radius: 12px; padding: 8px 10px; }
.actions { display: flex; gap: 8px; }
.actions button { height: 46px; border-radius: 14px; font-size: 15px; font-weight: 700; }
.ghost { flex: 1; border: 1.5px solid var(--c-line); background: var(--c-surface); color: var(--c-ink-2); }
.solid { flex: 2; border: none; background: var(--c-primary); color: #fff; }
.line { flex: 1; border: 1.5px solid var(--c-primary-line); background: var(--c-surface); color: var(--c-primary-ink); }
.accepted .actions .solid { flex: 1; }

.accepted { padding: 0; overflow: hidden; border: 2px solid var(--c-primary); box-shadow: 0 4px 16px rgba(108,92,231,.14); }
.banner { background: var(--c-primary); color: #fff; padding: 10px 14px; display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; }
.inner { padding: 14px; display: flex; flex-direction: column; gap: 12px; }
.pill { background: var(--c-primary-soft); color: var(--c-primary-deep); font-size: 13px; font-weight: 700; padding: 4px 10px; border-radius: 10px; }
.new { font-size: 12px; font-weight: 700; color: var(--c-primary-ink); }
.grid3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; }

.rejected { flex-direction: row; align-items: center; gap: 10px; border-radius: 18px; padding: 12px 14px; box-shadow: none; }
.rejected b { font-size: 14px; color: var(--c-ink-2); }
.undo { height: 36px; padding: 0 12px; border: none; border-radius: 12px; background: var(--c-primary-soft); color: var(--c-primary-deep); font-size: 13px; font-weight: 700; }

.sent { flex-direction: row; align-items: center; gap: 10px; border-radius: 20px; }
.sent b { font-size: 15px; }
.cancel { height: 36px; padding: 0 12px; border: 1.5px solid var(--c-line); border-radius: 12px; background: var(--c-surface); color: var(--c-ink-2); font-size: 13px; font-weight: 700; }
.foot { margin: 0; font-size: 12px; color: var(--c-muted); text-align: center; }
</style>
