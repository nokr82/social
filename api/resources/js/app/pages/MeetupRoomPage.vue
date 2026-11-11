<script setup>
/**
 * 번개 채팅방 (디자인: BungaeRoom.dc.html)
 * - 상단 고정: 시간·정확한 장소·지도 + [도착했어요]
 * - 약속 시간이 지나면 '안 왔어요' 상호 확인 (노쇼는 2표, 2인 번개는 1표로 확정)
 * - 약속 24시간 뒤 자동 종료 (읽기만 가능)
 * 실시간: private 채널 meetup.{id} → '.meetup.message' (일반·안내 메시지)
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import AttachSheet from '../components/AttachSheet.vue';
import AvatarFace from '../components/AvatarFace.vue';
import MessageAttachment from '../components/MessageAttachment.vue';
import { ApiError, get, post } from '../lib/api';
import { auth } from '../lib/auth';
import { connectEcho } from '../lib/echo';
import { ago, meetupWhen, until } from '../lib/time';
import { sendChatImage } from '../lib/chatAttach';
import { showToast } from '../lib/toast';

const props = defineProps({ id: { type: String, required: true } });
const router = useRouter();

const room = ref(null);
const messages = ref([]);
const draft = ref('');
const sending = ref(false);
const showNoshow = ref(false);
const listEl = ref(null);
const channel = `meetup.${props.id}`;

const meId = computed(() => auth.me?.id);
const me = computed(() => room.value?.members.find((m) => m.is_me));
const arrivedN = computed(() => room.value?.members.filter((m) => m.arrived).length ?? 0);
const hostId = computed(() => room.value?.host.id);
const [whenDay, whenTime] = [
    computed(() => room.value ? meetupWhen(room.value.starts_at).split(' ')[0] : ''),
    computed(() => room.value ? meetupWhen(room.value.starts_at).split(' ').slice(2).join(' ') : ''),
];
// 지도 SDK 없이 카카오맵 검색으로 열기 (정확한 장소 이름 기준)
const mapUrl = computed(() => room.value?.place_detail ? `https://map.kakao.com/?q=${encodeURIComponent(room.value.place_detail)}` : null);

async function load() {
    try {
        if (!auth.me) auth.me = (await get('/me')).data;
        const [r, msgs] = await Promise.all([get(`/meetups/${props.id}/room`), get(`/meetups/${props.id}/messages`)]);
        room.value = r.data;
        messages.value = msgs.data.reverse();
        await scrollToBottom();
        markRead();
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '채팅방에 들어갈 수 없어요');
        router.replace({ name: 'meetup', params: { id: props.id } });
    }
}

async function refreshRoom() {
    try { room.value = (await get(`/meetups/${props.id}/room`)).data; } catch { /* 다음 이벤트에 다시 */ }
}

function listen() {
    connectEcho().private(channel).listen('.meetup.message', (msg) => {
        if (messages.value.some((m) => m.id === msg.id)) return;
        messages.value.push(msg);
        scrollToBottom();
        markRead();
        // 참여·취소·도착 안내가 오면 상단 카드(인원·도착 현황)를 다시 불러옴
        if (msg.type === 'system') refreshRoom();
    });
}

function markRead() {
    const last = messages.value[messages.value.length - 1];
    if (last && document.visibilityState === 'visible') post(`/meetups/${props.id}/read`, { last_id: last.id }).catch(() => {});
}

async function send() {
    const body = draft.value.trim();
    if (!body || sending.value) return;
    sending.value = true;
    try {
        const res = await post(`/meetups/${props.id}/messages`, { body });
        draft.value = '';
        messages.value.push(res.data);
        scrollToBottom();
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '메시지를 보내지 못했어요');
    } finally {
        sending.value = false;
    }
}

/* 사진 보내기 ('+' → 시트 → 파일) */
const attaching = ref(false);
const uploading = ref(false);
async function sendImage(file) {
    attaching.value = false;
    uploading.value = true;
    try {
        messages.value.push(await sendChatImage(`/meetups/${props.id}/images`, file));
        scrollToBottom();
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : (e.message || '사진을 보내지 못했어요'));
    } finally {
        uploading.value = false;
    }
}

async function toggleArrive() {
    try {
        const { arrived } = await post(`/meetups/${props.id}/arrive`);
        me.value.arrived = arrived;
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '잠시 후 다시 시도해 주세요');
    }
}

/** '안 왔어요' 체크 (한 번 체크하면 취소 불가 — 노쇼 확정 후 되돌리기 혼선 방지) */
async function voteNoshow(member) {
    try {
        const res = await post(`/meetups/${props.id}/noshow`, { user_id: member.id });
        room.value.my_noshow_votes.push(member.id);
        if (res.confirmed) member.noshow = true;
        showToast(res.confirmed ? `${member.nickname}님 노쇼가 확정됐어요` : `체크했어요 (${res.votes}/${res.needed})`);
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '체크하지 못했어요');
    }
}

async function scrollToBottom() {
    await nextTick();
    if (listEl.value) listEl.value.scrollTop = listEl.value.scrollHeight;
}

const isArrivalNote = (m) => m.type === 'system' && m.body.endsWith('도착했어요');

onMounted(async () => {
    await load();
    if (room.value) listen();
});
onBeforeUnmount(() => window.Echo?.leave(channel));
</script>

<template>
    <div class="room">
        <!-- 헤더 -->
        <div class="head">
            <button class="icon" aria-label="뒤로가기 (채팅 목록)" @click="router.push({ name: 'chats' })">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
            </button>
            <div class="title-col">
                <span class="t">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#5B4BD6" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2L4 14h7l-1 8 9-12h-7z" /></svg>
                    <h1>{{ room?.title }}</h1>
                </span>
                <small>참여자 {{ room?.joined_count ?? 0 }}명</small>
            </div>
            <button class="icon" aria-label="번개 정보" @click="router.push({ name: 'meetup', params: { id } })">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 11v5M12 8h.01" /></svg>
            </button>
        </div>

        <!-- 고정 카드: 약속 정보 + 도착 체크 -->
        <div v-if="room" class="pin">
            <div class="pin-row">
                <span class="date"><small>{{ whenDay }}</small><b>{{ whenTime }}</b></span>
                <span class="col">
                    <b>{{ room.place_detail }}</b>
                    <small>{{ until(room.starts_at) }} · 도착 {{ arrivedN }}/{{ room.joined_count }}명</small>
                </span>
                <a v-if="mapUrl" class="map" :href="mapUrl" target="_blank" rel="noopener" aria-label="지도 보기">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#5B4BD6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 4L3 6v14l6-2 6 2 6-2V4l-6 2z" /><path d="M9 4v14M15 6v14" /></svg>
                </a>
            </div>
            <template v-if="room.room_open">
                <button v-if="!me?.arrived" class="arrive" @click="toggleArrive">도착했어요</button>
                <button v-else class="arrive done" @click="toggleArrive">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#1E7A4C" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5L20 7" /></svg>
                    도착 완료 · 다른 사람에게 알렸어요
                </button>
                <button v-if="room.can_vote_noshow" class="noshow-link" @click="showNoshow = true">안 온 사람이 있나요?</button>
            </template>
        </div>

        <!-- 메시지 -->
        <div ref="listEl" class="msgs scroll" aria-live="polite">
            <div class="sys">번개 채팅방은 약속 24시간 뒤 자동으로 닫혀요</div>
            <template v-for="m in messages" :key="m.id">
                <div v-if="m.type === 'system'" class="sys" :class="{ arrived: isArrivalNote(m) }">
                    <svg v-if="isArrivalNote(m)" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#1E7A4C" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5L20 7" /></svg>
                    {{ m.body }}
                </div>
                <div v-else-if="m.sender?.id === meId" class="mine">
                    <MessageAttachment v-if="m.type !== 'text'" :message="m" mine />
                    <span v-else class="b">{{ m.body }}</span>
                    <span class="time">{{ ago(m.created_at) }}</span>
                </div>
                <div v-else class="other">
                    <AvatarFace :parts="m.sender?.avatar" :size="32" variant="mini" style="flex-shrink: 0" />
                    <div class="col2">
                        <span class="who">{{ m.sender?.nickname }} <i v-if="m.sender?.id === hostId">호스트</i></span>
                        <MessageAttachment v-if="m.type !== 'text'" :message="m" />
                        <span v-else class="b">{{ m.body }}</span>
                    </div>
                </div>
            </template>
            <div v-if="uploading" class="mine"><span class="b" style="opacity: .6">사진 보내는 중…</span></div>
            <div v-if="room && !room.room_open" class="sys">{{ room.status === 'canceled' ? '취소된 번개예요' : '채팅방이 닫혔어요' }}</div>
        </div>

        <!-- 입력 -->
        <form class="compose" @submit.prevent="send">
            <button type="button" class="plus" aria-label="사진 보내기" :disabled="uploading || room?.room_open === false" @click="attaching = true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#5B4BD6" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
            </button>
            <label class="field">
                <span class="sr-only">메시지 입력</span>
                <input v-model="draft" type="text" maxlength="500" :placeholder="room?.room_open === false ? '닫힌 채팅방이에요' : '메시지 보내기'" :disabled="room?.room_open === false" enterkeyhint="send">
            </label>
            <button type="submit" class="send" aria-label="보내기" :disabled="!draft.trim() || sending || room?.room_open === false">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
            </button>
        </form>

        <AttachSheet v-if="attaching" @close="attaching = false" @image="sendImage" />

        <!-- 노쇼 상호 확인 -->
        <div v-if="showNoshow" class="dim" @click.self="showNoshow = false">
            <div class="sheet" role="dialog" aria-label="안 온 사람 체크">
                <div class="grip" />
                <b class="sheet-title">약속에 안 온 사람을 체크해 주세요</b>
                <small class="hint">참여자 2명 이상이 체크하면 노쇼로 기록되고 매너점수에 반영돼요. 체크는 취소할 수 없어요.</small>
                <div class="people">
                    <div v-for="p in room.members.filter((x) => !x.is_me)" :key="p.id" class="person">
                        <AvatarFace :parts="p.avatar" :size="36" variant="mini" />
                        <span class="pname">{{ p.nickname }}<small v-if="p.arrived"> · 도착 체크함</small></span>
                        <span v-if="p.noshow" class="tag">노쇼 확정</span>
                        <span v-else-if="room.my_noshow_votes.includes(p.id)" class="tag soft">체크함</span>
                        <button v-else class="vote" @click="voteNoshow(p)">안 왔어요</button>
                    </div>
                </div>
                <button class="close" @click="showNoshow = false">닫기</button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.room { height: 100%; display: flex; flex-direction: column; position: relative; }
.head { display: flex; align-items: center; gap: 6px; padding: 14px 8px 8px; background: var(--c-surface); }
.icon { width: 44px; height: 44px; border: none; background: none; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.title-col { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 1px; }
.t { display: flex; align-items: center; gap: 5px; min-width: 0; }
.t h1 { margin: 0; font-size: 16px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.title-col small { font-size: 12px; color: var(--c-muted); }

.pin { margin: 10px 12px 0; background: var(--c-surface); border-radius: 18px; padding: 12px 14px; display: flex; flex-direction: column; gap: 10px; box-shadow: 0 2px 10px rgba(108,92,231,.08); }
.pin-row { display: flex; align-items: center; gap: 10px; }
.date { display: flex; flex-direction: column; align-items: center; justify-content: center; width: 56px; height: 52px; border-radius: 14px; background: var(--c-primary); color: #fff; flex-shrink: 0; }
.date small { font-size: 11px; font-weight: 700; }
.date b { font-size: 14px; }
.col { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.col b { font-size: 14px; }
.col small { font-size: 12px; color: var(--c-primary-ink); font-weight: 700; }
.map { width: 44px; height: 44px; border-radius: 14px; background: var(--c-primary-soft); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.arrive { height: 44px; border: none; border-radius: 12px; background: var(--c-primary); color: #fff; font-size: 14px; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 6px; }
.arrive.done { background: #E6F8EF; color: var(--c-ok); }
.noshow-link { border: none; background: none; color: var(--c-muted); font-size: 12px; text-decoration: underline; padding: 0; align-self: center; }

.msgs { flex: 1; min-height: 0; padding: 14px 16px; display: flex; flex-direction: column; gap: 12px; }
.sys { align-self: center; display: flex; align-items: center; gap: 5px; font-size: 11px; color: var(--c-muted); background: var(--c-surface); padding: 4px 10px; border-radius: 10px; }
.sys.arrived { color: var(--c-ok); background: #E6F8EF; }
.other { display: flex; gap: 8px; align-items: flex-start; }
.col2 { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.who { font-size: 11px; color: var(--c-muted); font-weight: 700; }
.who i { font-style: normal; color: var(--c-primary-ink); }
.other .b { background: var(--c-surface); border-radius: 4px 16px 16px 16px; padding: 8px 12px; font-size: 14px; max-width: 240px; word-break: break-word; }
.mine { align-self: flex-end; display: flex; flex-direction: column; align-items: flex-end; gap: 3px; }
.mine .b { background: var(--c-primary); color: #fff; border-radius: 16px 4px 16px 16px; padding: 8px 12px; font-size: 14px; max-width: 240px; word-break: break-word; }
.time { font-size: 10px; color: var(--c-muted); }

.compose { display: flex; align-items: center; gap: 8px; padding: 10px 12px 24px; background: var(--c-surface); border-top: 1px solid var(--c-divider); }
.plus, .send { width: 44px; height: 44px; border-radius: 22px; border: none; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.plus { background: var(--c-primary-soft); }
.send { background: var(--c-primary); }
.send:disabled { opacity: .5; }
.field { flex: 1; height: 44px; background: var(--c-bg); border-radius: 22px; display: flex; align-items: center; padding: 0 16px; }
.field input { flex: 1; min-width: 0; border: none; outline: none; background: transparent; font-size: 14px; color: var(--c-ink); }

.dim { position: absolute; inset: 0; background: rgba(31,27,58,.45); display: flex; align-items: flex-end; z-index: 30; }
.sheet { width: 100%; max-height: 75%; background: var(--c-surface); border-radius: 28px 28px 0 0; padding: 10px 20px 30px; display: flex; flex-direction: column; gap: 10px; }
.grip { align-self: center; width: 40px; height: 5px; border-radius: 3px; background: var(--c-line); }
.sheet-title { font-size: 16px; }
.hint { font-size: 12px; color: var(--c-muted); line-height: 1.5; }
.people { display: flex; flex-direction: column; gap: 4px; overflow-y: auto; }
.person { display: flex; align-items: center; gap: 10px; padding: 8px 0; }
.pname { flex: 1; font-size: 15px; font-weight: 700; }
.pname small { font-weight: 500; color: var(--c-ok); font-size: 12px; }
.vote { height: 34px; padding: 0 12px; border: 1.5px solid var(--c-line); border-radius: 12px; background: var(--c-surface); color: var(--c-danger); font-size: 13px; font-weight: 700; }
.tag { font-size: 12px; font-weight: 700; color: var(--c-danger); }
.tag.soft { color: var(--c-muted); }
.close { height: 50px; border: none; background: none; font-size: 15px; font-weight: 700; color: var(--c-ink-2); }
</style>
