<script setup>
/**
 * 친구 1:1 대화 (디자인: DMRoom.dc.html)
 * - 헤더: 친구 아바타·성별·나이·접속 상태 (탭 → 프로필), ⋯ → 신고·차단
 * - 메시지 옆 상대 아바타도 탭 → 프로필
 * - 첫 줄: '○○에서 만나 친구가 됐어요'
 * - 내 마지막 메시지에 '읽음 · 12분 전' (상대 last_read_id 기준)
 * 실시간: private 채널 chat.{room} — '.dm.sent'(새 메시지), '.dm.read'(상대가 읽음)
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import AttachSheet from '../components/AttachSheet.vue';
import AvatarFace from '../components/AvatarFace.vue';
import MessageAttachment from '../components/MessageAttachment.vue';
import { ApiError, get, post } from '../lib/api';
import { auth } from '../lib/auth';
import { connectEcho } from '../lib/echo';
import { ago, genderAge } from '../lib/time';
import { sendChatImage } from '../lib/chatAttach';
import { showToast } from '../lib/toast';

const props = defineProps({ roomId: { type: String, required: true } });
const router = useRouter();

const room = ref(null);
const messages = ref([]);       // 오래된 → 최신
const otherRead = ref(0);       // 상대가 읽은 마지막 메시지 id
const draft = ref('');
const sending = ref(false);
const hasOlder = ref(true);
const listEl = ref(null);
const attaching = ref(false);   // '+' 시트
const uploading = ref(false);
const channel = `chat.${props.roomId}`;

const meId = computed(() => auth.me?.id);
// '읽음'은 상대가 읽은 범위 안의 '내 마지막 메시지' 하나에만 표시 (디자인)
const lastReadMineId = computed(() => [...messages.value].reverse()
    .find((m) => m.sender?.id === meId.value && m.id <= otherRead.value)?.id);
const lastMineId = computed(() => [...messages.value].reverse().find((m) => m.sender?.id === meId.value)?.id);

async function load() {
    try {
        if (!auth.me) auth.me = (await get('/me')).data;
        const [info, msgs] = await Promise.all([get(`/chats/${props.roomId}`), get(`/chats/${props.roomId}/messages`)]);
        room.value = info.data;
        otherRead.value = info.data.other_last_read_id;
        messages.value = msgs.data.reverse();
        hasOlder.value = msgs.data.length >= 50;
        await scrollToBottom();
        markRead();
        listen();
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '대화방을 열 수 없어요');
        router.replace({ name: 'chats' });
    }
}

function listen() {
    connectEcho().private(channel)
        .listen('.dm.sent', (msg) => {
            if (messages.value.some((m) => m.id === msg.id)) return;
            messages.value.push(msg);
            scrollToBottom();
            markRead(); // 보고 있으니 바로 읽음
        })
        .listen('.dm.read', (e) => {
            if (e.user_id !== meId.value) otherRead.value = Math.max(otherRead.value, e.last_read_id);
        });
}

/** 화면에 보이는 마지막 메시지까지 읽음 처리 (상대 메시지가 있을 때만) */
function markRead() {
    const last = messages.value[messages.value.length - 1];
    if (last && last.sender?.id !== meId.value && document.visibilityState === 'visible') {
        post(`/chats/${props.roomId}/read`, { last_id: last.id }).catch(() => {});
    }
}

async function send() {
    const body = draft.value.trim();
    if (!body || sending.value) return;
    sending.value = true;
    try {
        const res = await post(`/chats/${props.roomId}/messages`, { body });
        draft.value = '';
        messages.value.push(res.data);
        scrollToBottom();
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '메시지를 보내지 못했어요');
    } finally {
        sending.value = false;
    }
}

/** 사진 보내기: 시트에서 고른 파일 → 줄여서 업로드 */
async function sendImage(file) {
    attaching.value = false;
    uploading.value = true;
    try {
        messages.value.push(await sendChatImage(`/chats/${props.roomId}/images`, file));
        scrollToBottom();
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : (e.message || '사진을 보내지 못했어요'));
    } finally {
        uploading.value = false;
    }
}

/** 번개 공유: 내가 참여 중인 번개를 카드로 */
async function shareMeetup(id) {
    attaching.value = false;
    try {
        messages.value.push((await post(`/chats/${props.roomId}/meetups`, { meetup_id: id })).data);
        scrollToBottom();
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '공유하지 못했어요');
    }
}

async function onScroll() {
    const el = listEl.value;
    if (!el || el.scrollTop > 20 || !hasOlder.value || !messages.value.length) return;
    hasOlder.value = false;
    const prev = el.scrollHeight;
    const res = await get(`/chats/${props.roomId}/messages?before_id=${messages.value[0].id}`);
    messages.value.unshift(...res.data.reverse());
    hasOlder.value = res.data.length >= 50;
    await nextTick();
    el.scrollTop = el.scrollHeight - prev; // 보던 위치 유지
}

async function scrollToBottom() {
    await nextTick();
    if (listEl.value) listEl.value.scrollTop = listEl.value.scrollHeight;
}

function openReport() {
    const u = room.value.user;
    router.push({ name: 'report', params: { userId: u.id }, query: { nickname: u.nickname, color: u.avatar?.color, face: u.avatar?.face } });
}

// 다른 탭에 있다가 돌아오면 읽음 처리
const onVisible = () => document.visibilityState === 'visible' && markRead();

/** 상대 프로필로 (탈퇴 등으로 보낸 사람 정보가 없으면 무시) */
function openProfile(sender) {
    if (sender?.id) router.push({ name: 'profile', params: { id: sender.id } });
}

onMounted(() => {
    load();
    document.addEventListener('visibilitychange', onVisible);
});
onBeforeUnmount(() => {
    window.Echo?.leave(channel);
    document.removeEventListener('visibilitychange', onVisible);
});
</script>

<template>
    <div class="dm">
        <!-- 헤더 -->
        <div class="head">
            <button class="icon" aria-label="뒤로가기 (채팅 목록)" @click="router.push({ name: 'chats' })">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
            </button>
            <button v-if="room" class="who" :aria-label="`${room.user.nickname} 프로필`" @click="router.push({ name: 'profile', params: { id: room.user.id } })">
                <span class="av">
                    <AvatarFace :parts="room.user.avatar" :size="40" variant="mini" />
                    <i v-if="room.user.online" class="dot" />
                </span>
                <span class="col">
                    <span class="name"><b>{{ room.user.nickname }}</b><span class="ga">{{ genderAge(room.user) }}</span></span>
                    <small :class="{ live: room.user.online }">{{ room.user.online ? '지금 접속 중' : (room.user.last_seen_at ? `${ago(room.user.last_seen_at)} 접속` : '') }}</small>
                </span>
            </button>
            <span v-else class="who" />
            <button v-if="room" class="icon" aria-label="더보기 (신고·차단)" @click="openReport">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="#1F1B3A" aria-hidden="true"><circle cx="5" cy="12" r="2" /><circle cx="12" cy="12" r="2" /><circle cx="19" cy="12" r="2" /></svg>
            </button>
        </div>

        <!-- 메시지 -->
        <div ref="listEl" class="msgs scroll" aria-live="polite" @scroll.passive="onScroll">
            <div v-if="room" class="met">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#5B4BD6" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5L20 7" /></svg>
                {{ room.met_at ? `${room.met_at}에서 만나 친구가 됐어요` : '친구가 됐어요. 먼저 인사해 보세요!' }}
            </div>

            <template v-for="m in messages" :key="m.id">
                <div v-if="m.sender?.id === meId" class="mine">
                    <MessageAttachment v-if="m.type !== 'text'" :message="m" mine />
                    <span v-else class="b">{{ m.body }}</span>
                    <span v-if="m.id === lastReadMineId" class="meta">읽음 · {{ ago(m.created_at) }}</span>
                    <span v-else-if="m.id === lastMineId" class="meta">{{ ago(m.created_at) }}</span>
                </div>
                <div v-else class="other">
                    <button class="av-btn" :aria-label="`${m.sender?.nickname ?? '상대'} 프로필 보기`" @click="openProfile(m.sender)">
                        <AvatarFace :parts="m.sender?.avatar" :size="32" variant="mini" />
                    </button>
                    <MessageAttachment v-if="m.type !== 'text'" :message="m" />
                    <span v-else class="b">{{ m.body }}</span>
                </div>
            </template>
            <div v-if="uploading" class="mine"><span class="b sending">사진 보내는 중…</span></div>
        </div>

        <!-- 입력 -->
        <form class="compose" @submit.prevent="send">
            <button type="button" class="plus" aria-label="사진·번개 공유" :disabled="uploading" @click="attaching = true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#5B4BD6" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
            </button>
            <label class="field">
                <span class="sr-only">메시지 입력</span>
                <input v-model="draft" type="text" maxlength="1000" placeholder="메시지 보내기" enterkeyhint="send">
            </label>
            <button type="submit" class="send" aria-label="보내기" :disabled="!draft.trim() || sending">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
            </button>
        </form>

        <AttachSheet v-if="attaching" allow-meetup @close="attaching = false" @image="sendImage" @meetup="shareMeetup" />
    </div>
</template>

<style scoped>
.dm { height: 100%; display: flex; flex-direction: column; }
.sending { opacity: .6; }
.head { display: flex; align-items: center; gap: 6px; padding: 14px 8px 8px; background: var(--c-surface); }
.icon { width: 44px; height: 44px; border: none; background: none; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.who { flex: 1; min-width: 0; display: flex; align-items: center; gap: 10px; border: none; background: none; padding: 0; text-align: left; }
.av { position: relative; flex-shrink: 0; display: flex; }
.dot { position: absolute; right: 0; bottom: 0; width: 11px; height: 11px; border-radius: 6px; background: var(--c-live); border: 2px solid #fff; }
.col { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.name { display: flex; align-items: center; gap: 6px; }
.name b { font-size: 16px; }
.ga { background: var(--c-primary-soft); color: var(--c-primary-deep); font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 8px; }
.col small { font-size: 12px; color: var(--c-muted); }
.col small.live { color: var(--c-live-ink); font-weight: 700; }

.msgs { flex: 1; min-height: 0; padding: 14px 16px; display: flex; flex-direction: column; gap: 12px; }
.met { align-self: center; display: flex; align-items: center; gap: 6px; font-size: 11px; color: var(--c-primary-deep); background: #ECE8FF; padding: 5px 12px; border-radius: 12px; }
.other { display: flex; gap: 8px; align-items: flex-start; }
.av-btn { flex-shrink: 0; padding: 0; border: none; background: none; display: flex; border-radius: 16px; }
.other .b { background: var(--c-surface); border-radius: 4px 16px 16px 16px; padding: 8px 12px; font-size: 14px; max-width: 240px; word-break: break-word; }
.mine { align-self: flex-end; display: flex; flex-direction: column; align-items: flex-end; gap: 3px; }
.mine .b { background: var(--c-primary); color: #fff; border-radius: 16px 4px 16px 16px; padding: 8px 12px; font-size: 14px; max-width: 240px; word-break: break-word; }
.meta { font-size: 10px; color: var(--c-muted); }

.compose { display: flex; align-items: center; gap: 8px; padding: 10px 12px 24px; background: var(--c-surface); border-top: 1px solid var(--c-divider); }
.plus, .send { width: 44px; height: 44px; border-radius: 22px; border: none; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.plus { background: var(--c-primary-soft); }
.send { background: var(--c-primary); }
.send:disabled { opacity: .5; }
.field { flex: 1; height: 44px; background: var(--c-bg); border-radius: 22px; display: flex; align-items: center; padding: 0 16px; }
.field input { flex: 1; min-width: 0; border: none; outline: none; background: transparent; font-size: 14px; color: var(--c-ink); }
</style>
