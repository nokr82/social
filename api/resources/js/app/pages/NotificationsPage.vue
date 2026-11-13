<script setup>
/**
 * 알림센터 (메인 상단 종 아이콘)
 * - 친구 요청·수락, 번개 참여·취소·1시간 전 (DM·번개방 메시지는 채팅 탭에서 확인)
 * - 누르면 읽음 처리 후 해당 화면으로, '모두 읽음'
 */
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import AvatarFace from '../components/AvatarFace.vue';
import { ApiError, get, post } from '../lib/api';
import { notify } from '../lib/notify';
import { ago } from '../lib/time';
import { showToast } from '../lib/toast';

const router = useRouter();
const items = ref([]);
const page = ref(1);
const hasMore = ref(false);
const loading = ref(true);

// 아바타가 없는 알림(1시간 전 알림 등)에 쓸 아이콘
const ICONS = { meetup_reminder: '⏰', meetup_canceled: '🌧️', meetup_joined: '⚡', friend_request: '💌', friend_accepted: '🤝', location_share_started: '📍', location_viewed: '👀' };

async function load(more = false) {
    loading.value = true;
    try {
        const res = await get(`/notifications?page=${more ? page.value + 1 : 1}`);
        items.value = more ? [...items.value, ...res.data] : res.data;
        page.value = more ? page.value + 1 : 1;
        hasMore.value = res.has_more;
        notify.unread = res.unread;
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '불러오지 못했어요');
    } finally {
        loading.value = false;
    }
}

async function open(n) {
    if (!n.read) {
        n.read = true;
        post(`/notifications/${n.id}/read`).then((r) => (notify.unread = r.unread)).catch(() => {});
    }
    router.push(n.url);
}

async function readAll() {
    await post('/notifications/read-all').catch(() => {});
    items.value.forEach((n) => (n.read = true));
    notify.unread = 0;
}

onMounted(() => load());
</script>

<template>
    <div class="nt">
        <div class="head">
            <button class="icon" aria-label="뒤로가기" @click="router.back()">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
            </button>
            <h1 class="title" style="font-size: 22px; flex: 1">알림</h1>
            <button v-if="notify.unread" class="all" @click="readAll">모두 읽음</button>
        </div>

        <div class="list scroll">
            <p v-if="!loading && !items.length" class="empty">아직 알림이 없어요</p>
            <button v-for="n in items" :key="n.id" class="row" :class="{ unread: !n.read }" @click="open(n)">
                <span class="av">
                    <AvatarFace v-if="n.actor?.avatar" :parts="n.actor.avatar" :size="38" />
                    <span v-else class="emoji" aria-hidden="true">{{ ICONS[n.kind] ?? '🔔' }}</span>
                </span>
                <span class="col">
                    <b>{{ n.title }}</b>
                    <span class="body">{{ n.body }}</span>
                    <small>{{ ago(n.created_at) }}</small>
                </span>
                <span v-if="!n.read" class="dot" aria-label="안 읽음" />
            </button>
            <button v-if="hasMore" class="more" :disabled="loading" @click="load(true)">더 보기</button>
        </div>
    </div>
</template>

<style scoped>
.nt { height: 100%; display: flex; flex-direction: column; }
.head { display: flex; align-items: center; gap: 4px; padding: 14px 16px 6px 8px; }
.icon { width: 44px; height: 44px; border: none; background: none; display: flex; align-items: center; justify-content: center; }
.all { height: 36px; padding: 0 12px; border: none; border-radius: 12px; background: var(--c-primary-soft); color: var(--c-primary-deep); font-size: 13px; font-weight: 700; }
.list { flex: 1; min-height: 0; padding: 10px 16px 32px; display: flex; flex-direction: column; gap: 8px; }
.empty { margin: 40px 0 0; text-align: center; font-size: 14px; color: var(--c-muted); }
.row { display: flex; align-items: flex-start; gap: 12px; text-align: left; border: none; background: var(--c-surface); border-radius: 18px; padding: 12px 14px; }
.row.unread { background: #EFEBFF; }
.av { width: 44px; height: 44px; border-radius: 22px; background: var(--c-primary-soft); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.emoji { font-size: 22px; }
.col { flex: 1; display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.col b { font-size: 14px; color: var(--c-ink); }
.body { font-size: 14px; color: var(--c-ink-2); line-height: 1.4; }
.col small { font-size: 12px; color: var(--c-muted); }
.dot { width: 8px; height: 8px; border-radius: 4px; background: var(--c-primary); margin-top: 6px; flex-shrink: 0; }
.more { height: 44px; border: 1.5px solid var(--c-line); border-radius: 14px; background: var(--c-surface); color: var(--c-ink-2); font-size: 14px; font-weight: 700; }
</style>
