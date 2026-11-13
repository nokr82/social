<script setup>
/**
 * 채팅 탭 (디자인: ChatTab.dc.html)
 * [대화] 친구와의 1:1 대화 목록 (번개 채팅방은 번개 기능과 함께 추가)
 * [친구] 검색 · 받은 요청 · 접속 상태 · 성별·나이 · 대화 버튼
 * 새 메시지는 내 개인 채널로 받아 목록·배지를 바로 갱신한다.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AvatarFace from '../components/AvatarFace.vue';
import MeetupCard from '../components/MeetupCard.vue';
import TabBar from '../components/TabBar.vue';
import { ApiError, get } from '../lib/api';
import { auth } from '../lib/auth';
import { openDm } from '../lib/dm';
import { connectEcho } from '../lib/echo';
import { previewOf } from '../lib/chatAttach';
import { ago, genderAge, presenceText } from '../lib/time';
import { showToast } from '../lib/toast';

const route = useRoute();
const router = useRouter();

const tab = ref(route.query.tab === 'friends' ? 'friends' : 'chats');
const chats = ref([]);
const meetupRooms = ref([]);   // 번개 채팅방 (상단 고정)
const friends = ref([]);
const receivedCount = ref(0);
const q = ref('');
const loading = ref(true);
let channelName = null;

const onlineN = computed(() => friends.value.filter((f) => f.online).length);
const filtered = computed(() => {
    const k = q.value.trim();
    return k ? friends.value.filter((f) => f.nickname.includes(k)) : friends.value;
});

async function load() {
    loading.value = true;
    try {
        if (!auth.me) auth.me = (await get('/me')).data;
        const [c, f, mt] = await Promise.all([get('/chats'), get('/friends'), get('/meetups/mine')]);
        meetupRooms.value = mt.data;
        chats.value = c.data;
        friends.value = f.data;
        receivedCount.value = auth.me.stats?.received_requests ?? 0;
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '불러오지 못했어요');
    } finally {
        loading.value = false;
    }
}

/** 새 DM이 오면 해당 대화를 맨 위로 올리고 안 읽은 수 +1 */
function onIncoming(msg) {
    const i = chats.value.findIndex((c) => c.room_id === msg.room_id);
    if (i === -1) return load(); // 처음 온 대화면 목록 새로 고침
    const [chat] = chats.value.splice(i, 1);
    chat.last_message = { body: previewOf(msg), type: msg.type, mine: false, created_at: msg.created_at };
    chat.unread++;
    chats.value.unshift(chat);
}

watch(tab, (t) => router.replace({ query: t === 'friends' ? { tab: 'friends' } : {} }));

onMounted(async () => {
    await load();
    if (auth.me) {
        channelName = `App.Models.User.${auth.me.id}`;
        connectEcho().private(channelName).listen('.dm.sent', onIncoming);
    }
});
// 개인 채널은 알림(lib/notify.js)도 같이 쓰므로 leave 금지 → 이 화면의 이벤트만 해제
onBeforeUnmount(() => channelName && window.Echo?.private(channelName).stopListening('.dm.sent', onIncoming));
</script>

<template>
    <div class="chat-tab">
        <div class="head">
            <h1 class="title" style="font-size: 26px; flex: 1">채팅</h1>
            <button class="round" aria-label="친구 지도" @click="router.push({ name: 'map' })">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z" /><circle cx="12" cy="10" r="2.5" /></svg>
            </button>
            <button class="round" aria-label="친구 검색" @click="tab = 'friends'">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" /></svg>
            </button>
        </div>

        <div class="tabs" role="tablist" aria-label="채팅 보기">
            <button role="tab" :aria-selected="tab === 'chats'" :class="{ on: tab === 'chats' }" @click="tab = 'chats'">대화</button>
            <button role="tab" :aria-selected="tab === 'friends'" :class="{ on: tab === 'friends' }" @click="tab = 'friends'">친구 {{ friends.length }}</button>
        </div>

        <div class="body scroll">
            <!-- 대화 목록 -->
            <template v-if="tab === 'chats'">
                <section v-if="meetupRooms.length" class="sec">
                    <h2>번개 채팅방</h2>
                    <button v-for="m in meetupRooms" :key="m.id" class="plain" @click="router.push({ name: 'meetup-room', params: { id: m.id } })">
                        <MeetupCard :m="m" highlight>
                            <span class="last">{{ m.last_message ? `${m.last_message.sender ? `${m.last_message.sender}: ` : ''}${m.last_message.body}` : '아직 대화가 없어요' }}</span>
                            <template #side>
                                <span class="side">
                                    <small v-if="m.last_message">{{ ago(m.last_message.created_at) }}</small>
                                    <span v-if="m.unread" class="badge">{{ m.unread > 99 ? '99+' : m.unread }}</span>
                                </span>
                            </template>
                        </MeetupCard>
                    </button>
                </section>
                <section class="sec">
                    <h2>친구와 대화</h2>
                    <div v-if="chats.length" class="list">
                        <button v-for="c in chats" :key="c.room_id" class="row" @click="router.push({ name: 'dm', params: { roomId: c.room_id } })">
                            <AvatarFace :parts="c.user.avatar" :size="48" variant="mini" />
                            <span class="col">
                                <b>{{ c.user.nickname }}</b>
                                <span class="last">{{ c.last_message.mine ? '나: ' : '' }}{{ c.last_message.body }}</span>
                            </span>
                            <span class="side">
                                <small>{{ ago(c.last_message.created_at) }}</small>
                                <span v-if="c.unread" class="badge">{{ c.unread > 99 ? '99+' : c.unread }}</span>
                            </span>
                        </button>
                    </div>
                    <p v-else-if="!loading" class="empty">아직 대화가 없어요.<br>친구 탭에서 대화를 시작해 보세요!</p>
                    <span class="note">1:1 대화는 친구끼리만 할 수 있어요</span>
                </section>
            </template>

            <!-- 친구 목록 -->
            <template v-else>
                <label class="search">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#8A85A3" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" /></svg>
                    <span class="sr-only">친구 검색</span>
                    <input v-model="q" type="search" placeholder="친구 닉네임 검색">
                </label>

                <button class="requests" @click="router.push({ name: 'friend-requests' })">
                    <span class="l">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#5B4BD6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3.5" /><path d="M2.5 20a6.5 6.5 0 0 1 13 0" /><path d="M19 8v6M16 11h6" /></svg>
                        받은 친구 요청
                    </span>
                    <span class="l">
                        <span v-if="receivedCount" class="badge">{{ receivedCount }}</span>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#5B4BD6" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6" /></svg>
                    </span>
                </button>

                <h2 class="count">친구 {{ friends.length }}명 · 지금 접속 {{ onlineN }}명</h2>
                <div v-if="filtered.length" class="list">
                    <div v-for="f in filtered" :key="f.id" class="row">
                        <button class="who" :aria-label="`${f.nickname} 프로필`" @click="router.push({ name: 'profile', params: { id: f.id } })">
                            <span class="av">
                                <AvatarFace :parts="f.avatar" :size="46" variant="mini" />
                                <i v-if="f.online" class="dot" />
                            </span>
                            <span class="col">
                                <span class="name"><b>{{ f.nickname }}</b><span class="ga">{{ genderAge(f) }}</span></span>
                                <small>{{ presenceText(f) }}</small>
                            </span>
                        </button>
                        <button class="talk" :aria-label="`${f.nickname}님과 대화하기`" @click="openDm(router, f.id)">대화</button>
                    </div>
                </div>
                <p v-else-if="!loading" class="empty">{{ q ? '검색 결과가 없어요' : '아직 친구가 없어요.\n광장에서 친구 요청을 보내보세요!' }}</p>
            </template>
        </div>

        <TabBar active="chat" />
    </div>
</template>

<style scoped>
.chat-tab { height: 100%; display: flex; flex-direction: column; position: relative; }
.head { display: flex; align-items: center; justify-content: space-between; gap: 4px; padding: 18px 12px 6px 20px; }
.round { width: 44px; height: 44px; border-radius: 22px; border: none; background: var(--c-surface); display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-soft); }
.tabs { margin: 4px 20px 0; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 4px; background: #ECE8FF; padding: 4px; border-radius: 14px; }
.tabs button { height: 40px; border: none; border-radius: 11px; background: transparent; color: var(--c-muted); font-size: 14px; font-weight: 500; }
.tabs button.on { background: var(--c-surface); color: var(--c-ink); font-weight: 700; box-shadow: 0 1px 4px rgba(31,27,58,.10); }
.body { flex: 1; min-height: 0; padding: 16px 20px 100px; display: flex; flex-direction: column; gap: 12px; }

.sec { display: flex; flex-direction: column; gap: 8px; }
h2 { margin: 0; font-size: 13px; font-weight: 700; color: var(--c-muted); }
.count { margin-top: 4px; }
.list { background: var(--c-surface); border-radius: 18px; overflow: hidden; }
.row { width: 100%; border: none; background: none; padding: 12px; display: flex; gap: 12px; align-items: center; text-align: left; border-bottom: 1px solid var(--c-primary-soft); }
.row:last-child { border-bottom: none; }
.col { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
.col b { font-size: 15px; color: var(--c-ink); }
.col small { font-size: 12px; color: var(--c-muted); }
.last { font-size: 13px; color: var(--c-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.side { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; flex-shrink: 0; }
.side small { font-size: 11px; color: var(--c-muted); }
.badge { min-width: 20px; height: 20px; border-radius: 10px; background: #E04A36; color: #fff; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; padding: 0 5px; }
.plain { border: none; background: none; padding: 0; text-align: left; width: 100%; }
.note { font-size: 12px; color: var(--c-muted); text-align: center; }
.empty { margin: 0; padding: 28px 20px; background: var(--c-surface); border-radius: 18px; text-align: center; font-size: 14px; color: var(--c-muted); line-height: 1.6; white-space: pre-line; }

.search { display: flex; align-items: center; gap: 10px; background: var(--c-surface); border-radius: 14px; padding: 0 14px; height: 48px; }
.search input { flex: 1; border: none; outline: none; background: transparent; font-size: 14px; color: var(--c-ink); }
.requests { border: none; background: var(--c-primary-soft); border-radius: 14px; height: 52px; padding: 0 14px; display: flex; align-items: center; justify-content: space-between; color: var(--c-primary-deep); font-size: 14px; font-weight: 700; }
.l { display: flex; align-items: center; gap: 8px; }

.list .row { padding: 10px 12px; }
.who { flex: 1; min-width: 0; display: flex; gap: 12px; align-items: center; border: none; background: none; padding: 0; text-align: left; }
.av { position: relative; flex-shrink: 0; display: flex; }
.dot { position: absolute; right: 0; bottom: 2px; width: 12px; height: 12px; border-radius: 6px; background: var(--c-live); border: 2px solid #fff; }
.name { display: flex; align-items: center; gap: 6px; }
.ga { background: var(--c-primary-soft); color: var(--c-primary-deep); font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 8px; }
.talk { height: 36px; padding: 0 14px; border: 1.5px solid var(--c-primary-line); border-radius: 12px; background: var(--c-surface); color: var(--c-primary-ink); font-size: 13px; font-weight: 700; flex-shrink: 0; }
</style>
