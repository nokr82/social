<script setup>
/**
 * 광장 내부 (디자인: Plaza.dc.html)
 * 위: 가상 광장(아바타) / 아래: 채팅
 *
 * 실시간 구조
 *  - 입장: POST /plazas/{id}/enter → presence 채널 plaza.{id} 구독
 *  - 접속자: presence here/joining/leaving (서버 저장 없음)
 *  - 아바타 이동: whisper 'move' {id, x, y} — x,y는 0~1 비율 (화면 크기 무관)
 *  - 채팅: POST 저장 → 서버가 '.message.sent' 브로드캐스트 (보낸 나는 응답으로 바로 표시)
 *  - 30초마다 heartbeat, 화면을 떠나면 leave
 */
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import AvatarFace from '../components/AvatarFace.vue';
import FriendRequestSheet from '../components/FriendRequestSheet.vue';
import HeartIcon from '../components/HeartIcon.vue';
import LockIcon from '../components/LockIcon.vue';
import { ApiError, api, get, post } from '../lib/api';
import { auth } from '../lib/auth';
import { seededSpot } from '../lib/avatar';
import { connectEcho } from '../lib/echo';
import { getLocation } from '../lib/location';
import { comingSoon, showToast } from '../lib/toast';

const props = defineProps({ id: { type: String, required: true } });
const router = useRouter();

const plaza = ref(null);
const members = ref([]);              // presence 접속자 [{id, nickname, avatar}]
const positions = reactive({});       // userId → {x, y} (0~1 비율)
const bubbles = reactive({});         // userId → 말풍선 텍스트 (5초)
const messages = ref([]);             // 오래된 → 최신
const blocked = ref(new Set());       // 차단 관계 사용자 (양방향)
const selected = ref(null);           // 미니 카드로 연 사용자
const card = ref(null);               // 미니 카드용 프로필 (GET /users/{id})
const requesting = ref(false);
const showMembers = ref(false);
const draft = ref('');
const sending = ref(false);
const hasOlder = ref(true);
const chatEl = ref(null);

let channel = null;
let heartbeatTimer = null;
const bubbleTimers = {};

const meId = computed(() => auth.me?.id);
const AVATAR_W = 64;   // 아바타 버튼 폭 (위치 계산용)
const STAGE_H = 300;

/** 화면에 그릴 아바타: 접속자 + 위치 + 말풍선 */
const avatars = computed(() => members.value
    .filter((m) => !blocked.value.has(m.id))
    .map((m) => {
        const pos = positions[m.id] ?? spotFor(m.id); // placeNew 전 한 프레임 동안의 임시 자리
        return { ...m, pos, me: m.id === meId.value, bubble: bubbles[m.id] };
    }));

function spotFor(userId) {
    // 아직 위치를 모르는 사람은 ID로 정해진 자리 (모든 화면에서 동일)
    const s = seededSpot(userId, 1000, 1000, 120);
    return { x: s.x / 1000, y: s.y / 1000 };
}

/**
 * 초기 자리를 정해 저장한다. 이미 있는 아바타와 너무 가까우면(겹쳐서 누를 수 없음)
 * 시드를 바꿔 최대 8번 다시 뽑는다. 결정적이라 모든 화면에서 같은 결과가 나온다.
 */
function placeNew(userId) {
    if (positions[userId]) return;
    const taken = Object.values(positions);
    let spot = spotFor(userId);
    for (let k = 1; k <= 8 && taken.some((p) => Math.abs(p.x - spot.x) < 0.2 && Math.abs(p.y - spot.y) < 0.2); k++) {
        const s = seededSpot(userId + k * 100003, 1000, 1000, 120);
        spot = { x: s.x / 1000, y: s.y / 1000 };
    }
    positions[userId] = spot;
}

/* ---------------- 입장 · 퇴장 ---------------- */

async function enter() {
    try {
        if (!auth.me) auth.me = (await get('/me')).data;
        const loc = await getLocation();
        const [p] = await Promise.all([
            get(`/plazas/${props.id}?lat=${loc.lat}&lng=${loc.lng}`),
            post(`/plazas/${props.id}/enter`),
        ]);
        plaza.value = p.data;

        const [msgs, blocks] = await Promise.all([get(`/plazas/${props.id}/messages`), get('/blocks')]);
        messages.value = msgs.data.reverse();
        hasOlder.value = msgs.data.length >= 50;
        blocked.value = new Set(blocks.user_ids);
        scrollToBottom();

        joinChannel();
        // 앱 문서: 광장 화면에 있는 동안 30초마다 heartbeat
        heartbeatTimer = setInterval(() => post(`/plazas/${props.id}/heartbeat`).catch(onHeartbeatError), 30_000);
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '광장에 들어갈 수 없어요');
        router.replace({ name: 'main' });
    }
}

function onHeartbeatError(e) {
    // 네트워크가 끊겼다 돌아와 이탈 처리된 경우 → 다시 입장
    if (e instanceof ApiError && e.code === 'NOT_IN_PLAZA') post(`/plazas/${props.id}/enter`).catch(() => {});
}

function joinChannel() {
    const echo = connectEcho();
    channel = echo.join(`plaza.${props.id}`)
        .here((users) => {
            members.value = users;
            [...users].sort((a, b) => a.id - b.id).forEach((u) => placeNew(u.id));
            whisperMyPosition();
        })
        .joining((user) => {
            if (!members.value.some((m) => m.id === user.id)) members.value.push(user);
            placeNew(user.id);
            pushSystem(`${user.nickname}님이 광장에 들어왔어요`);
            whisperMyPosition(); // 새로 온 사람이 내 위치를 알 수 있게
        })
        .leaving((user) => {
            members.value = members.value.filter((m) => m.id !== user.id);
            delete positions[user.id];
            if (selected.value?.id === user.id) selected.value = null;
        })
        .listen('.message.sent', (msg) => {
            if (blocked.value.has(msg.sender?.id)) return; // 차단 관계는 실시간에서도 숨김
            appendMessage(msg);
        })
        .listenForWhisper('move', (e) => {
            if (typeof e?.id === 'number' && !blocked.value.has(e.id)) positions[e.id] = clampPos(e);
        })
        .error(() => showToast('실시간 연결에 문제가 있어요. 잠시 후 다시 들어와 주세요'));
}

function leave() {
    clearInterval(heartbeatTimer);
    Object.values(bubbleTimers).forEach(clearTimeout);
    window.Echo?.leave(`plaza.${props.id}`);
    // 화면을 떠나는 중에도 요청이 끝나도록 keepalive
    api('POST', `/plazas/${props.id}/leave`, {}, { keepalive: true }).catch(() => {});
}

/* ---------------- 아바타 이동 ---------------- */

function clampPos({ x, y }) {
    const c = (v) => Math.min(0.92, Math.max(0.08, Number(v) || 0.5));
    return { x: c(x), y: c(y) };
}

function whisperMyPosition() {
    const pos = positions[meId.value];
    if (channel && pos) channel.whisper('move', { id: meId.value, ...pos });
}

/** 광장 바닥을 탭하면 내 아바타가 그 자리로 이동 */
function moveTo(event) {
    const rect = event.currentTarget.getBoundingClientRect();
    positions[meId.value] = clampPos({
        x: (event.clientX - rect.left) / rect.width,
        y: (event.clientY - rect.top) / rect.height,
    });
    selected.value = null;
    whisperMyPosition();
}

/** 비율 좌표 → 화면 px (아바타 중심 기준) */
function avatarStyle(a) {
    return {
        left: `calc(${a.pos.x * 100}% - ${AVATAR_W / 2}px)`,
        top: `${a.pos.y * STAGE_H - (a.bubble ? 44 : 26)}px`,
    };
}

async function pick(a) {
    if (a.me) return;
    selected.value = a;
    card.value = null;
    try {
        const profile = (await get(`/users/${a.id}`)).data;
        if (selected.value?.id === a.id) card.value = profile; // 그 사이 다른 아바타를 눌렀으면 무시
    } catch {
        card.value = { photos: [], private_count: 0, public_hearts: 0, relation: 'none' };
    }
}

function onRequestSent() {
    requesting.value = false;
    if (card.value) card.value.relation = 'sent';
    showToast('친구 요청을 보냈어요');
}

function openProfile(user) {
    router.push({ name: 'profile', params: { id: user.id }, query: { from: props.id } });
}

/* ---------------- 채팅 ---------------- */

function appendMessage(msg) {
    if (messages.value.some((m) => m.id === msg.id)) return;
    messages.value.push(msg);
    if (msg.sender) showBubble(msg.sender.id, msg.body);
    scrollToBottom();
}

function pushSystem(text) {
    messages.value.push({ id: `sys-${Date.now()}-${Math.random()}`, type: 'system', body: text });
    scrollToBottom();
}

function showBubble(userId, text) {
    bubbles[userId] = text.length > 14 ? `${text.slice(0, 14)}…` : text;
    clearTimeout(bubbleTimers[userId]);
    bubbleTimers[userId] = setTimeout(() => delete bubbles[userId], 5000);
}

async function send() {
    const body = draft.value.trim();
    if (!body || sending.value) return;
    sending.value = true;
    try {
        const res = await post(`/plazas/${props.id}/messages`, { body });
        draft.value = '';
        appendMessage(res.data);
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '메시지를 보내지 못했어요');
    } finally {
        sending.value = false;
    }
}

/** 맨 위로 스크롤하면 이전 대화 불러오기 */
async function onChatScroll() {
    const el = chatEl.value;
    if (!el || el.scrollTop > 20 || !hasOlder.value) return;
    const oldest = messages.value.find((m) => typeof m.id === 'number');
    if (!oldest) return;
    hasOlder.value = false; // 중복 요청 방지
    const prevHeight = el.scrollHeight;
    const res = await get(`/plazas/${props.id}/messages?before_id=${oldest.id}`);
    messages.value.unshift(...res.data.reverse());
    hasOlder.value = res.data.length >= 50;
    await nextTick();
    el.scrollTop = el.scrollHeight - prevHeight; // 보던 위치 유지
}

async function scrollToBottom() {
    await nextTick();
    if (chatEl.value) chatEl.value.scrollTop = chatEl.value.scrollHeight;
}

function timeLabel(iso) {
    if (!iso) return '';
    const diff = (Date.now() - new Date(iso).getTime()) / 1000;
    if (diff < 60) return '방금';
    if (diff < 3600) return `${Math.floor(diff / 60)}분 전`;
    return new Date(iso).toLocaleTimeString('ko-KR', { hour: 'numeric', minute: '2-digit' });
}

function openReport(user) {
    router.push({
        name: 'report',
        params: { userId: user.id },
        query: { nickname: user.nickname, color: user.avatar?.color, face: user.avatar?.face, from: props.id },
    });
}

onMounted(enter);
onBeforeUnmount(leave);
</script>

<template>
    <div class="plaza">
        <!-- 헤더 -->
        <div class="head">
            <button class="icon" aria-label="뒤로가기 (광장 목록)" @click="router.push({ name: 'main' })">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
            </button>
            <div class="title-wrap">
                <h1 class="title name">{{ plaza?.name ?? ' ' }}</h1>
                <span class="sub">
                    <i class="dot" />
                    <b>{{ members.length || plaza?.live_count || 0 }}명 접속중</b>
                    <template v-if="plaza?.distance_label"> · {{ plaza.distance_label }}</template>
                    <template v-if="plaza"> · {{ plaza.category_label }}</template>
                </span>
            </div>
            <button class="icon round primary" aria-label="이 광장에 번개 만들기" @click="comingSoon('번개')">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2L4 14h7l-1 8 9-12h-7z" /></svg>
            </button>
            <button class="icon round" aria-label="참여자 목록" @click="showMembers = true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3.5" /><path d="M2.5 20a6.5 6.5 0 0 1 13 0" /><path d="M16 4.5a3.5 3.5 0 0 1 0 7" /><path d="M18 14.5a6.5 6.5 0 0 1 3.5 5.5" /></svg>
            </button>
        </div>

        <!-- 가상 광장: 바닥을 탭하면 내 아바타가 이동 -->
        <div class="stage" :style="{ height: `${STAGE_H}px` }" @click.self="moveTo">
            <!-- 바닥·소품 (장식, 클릭은 바닥으로 통과) -->
            <div class="deco ground" aria-hidden="true" />
            <div class="deco pad" aria-hidden="true" />
            <div class="deco tree t1" aria-hidden="true"><div /><div /></div>
            <div class="deco tree t2" aria-hidden="true"><div /><div /></div>
            <div class="deco bench" aria-hidden="true" />

            <button
                v-for="a in avatars" :key="a.id"
                class="avatar" :style="avatarStyle(a)"
                :aria-label="a.me ? '내 아바타' : `${a.nickname} 아바타`"
                @click.stop="pick(a)"
            >
                <span v-if="a.bubble" class="bubble">{{ a.bubble }}</span>
                <span class="face" :class="{ me: a.me }"><AvatarFace :parts="a.avatar" :size="40" /></span>
                <span class="tag">{{ a.me ? '나' : a.nickname }}</span>
            </button>

            <p v-if="members.length <= 1" class="alone" aria-live="polite">아직 혼자예요. 바닥을 눌러 돌아다녀 보세요!</p>

            <!-- 아바타 탭 → 미니 카드 -->
            <div v-if="selected" class="card" role="dialog" :aria-label="`${selected.nickname}님`">
                <div class="card-head">
                    <AvatarFace :parts="selected.avatar" :size="36" />
                    <span class="card-name">
                        <b>{{ selected.nickname }}</b>
                        <span v-if="card" class="card-hearts"><HeartIcon :size="13" />{{ card.public_hearts }}</span>
                    </span>
                    <button class="mini" aria-label="신고·차단 메뉴" @click="openReport(selected)">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="#1F1B3A" aria-hidden="true"><circle cx="5" cy="12" r="2" /><circle cx="12" cy="12" r="2" /><circle cx="19" cy="12" r="2" /></svg>
                    </button>
                    <button class="mini" aria-label="닫기" @click="selected = null">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
                    </button>
                </div>
                <!-- 공개 사진 3장 + 비공개 N장(잠금) -->
                <div class="photos">
                    <template v-if="card">
                        <button v-for="p in card.photos.slice(0, 3)" :key="p.id" class="ph" :aria-label="`${selected.nickname} 공개 사진`" @click="openProfile(selected)">
                            <img :src="p.url" alt="" loading="lazy">
                        </button>
                        <div v-for="i in Math.max(0, 3 - card.photos.length)" :key="`e${i}`" class="ph" aria-hidden="true" />
                        <div class="ph locked">
                            <LockIcon :size="16" />
                            비공개 {{ card.private_count }}
                        </div>
                    </template>
                    <div v-for="i in (card ? 0 : 4)" :key="`s${i}`" class="ph skeleton" aria-hidden="true" />
                </div>
                <div class="card-actions">
                    <button v-if="!card || card.relation === 'none'" class="solid" :disabled="!card" @click="requesting = true">친구 요청</button>
                    <button v-else-if="card.relation === 'sent'" class="solid muted" disabled>요청 보냄</button>
                    <button v-else-if="card.relation === 'received'" class="solid" @click="router.push({ name: 'friend-requests' })">받은 요청 보기</button>
                    <button v-else class="solid muted" disabled>친구</button>
                    <button class="line" @click="openProfile(selected)">프로필 보기</button>
                </div>
            </div>
        </div>

        <!-- 채팅 -->
        <div class="chat">
            <div ref="chatEl" class="msgs scroll" aria-live="polite" @scroll.passive="onChatScroll">
                <template v-for="m in messages" :key="m.id">
                    <div v-if="m.type === 'system'" class="sys">{{ m.body }}</div>
                    <div v-else-if="m.sender?.id === meId" class="mine">
                        <span class="b">{{ m.body }}</span>
                        <span class="time">{{ timeLabel(m.created_at) }}</span>
                    </div>
                    <div v-else class="other">
                        <AvatarFace :parts="m.sender?.avatar" :size="30" variant="mini" style="flex-shrink: 0" />
                        <div class="col">
                            <span class="who">{{ m.sender?.nickname }}</span>
                            <span class="b">{{ m.body }}</span>
                        </div>
                    </div>
                </template>
                <div v-if="!messages.length" class="sys">첫 인사를 건네보세요 👋</div>
            </div>

            <form class="compose" @submit.prevent="send">
                <button type="button" class="emote" aria-label="아바타 표정·동작" @click="comingSoon('표정·동작')">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#5B4BD6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M8.5 14.5q3.5 3 7 0" /><path d="M9 9.5h.01M15 9.5h.01" /></svg>
                </button>
                <label class="field">
                    <span class="sr-only">메시지 입력</span>
                    <input v-model="draft" type="text" maxlength="500" placeholder="광장에 한마디…" enterkeyhint="send">
                </label>
                <button type="submit" class="send" aria-label="보내기" :disabled="!draft.trim() || sending">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                </button>
            </form>
        </div>

        <FriendRequestSheet v-if="requesting && selected" :user="selected" :plaza-id="id" @close="requesting = false" @sent="onRequestSent" />

        <!-- 참여자 목록 시트 -->
        <div v-if="showMembers" class="dim" @click.self="showMembers = false">
            <div class="sheet" role="dialog" aria-label="참여자 목록">
                <div class="grip" />
                <b class="sheet-title">참여자 {{ members.length }}명</b>
                <div class="list scroll">
                    <button v-for="m in avatars" :key="m.id" class="person" @click="!m.me && ((selected = m), (showMembers = false))">
                        <AvatarFace :parts="m.avatar" :size="36" />
                        <span>{{ m.nickname }}<small v-if="m.me"> (나)</small></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.plaza { height: 100%; display: flex; flex-direction: column; position: relative; }

.head { display: flex; align-items: center; gap: 8px; padding: 14px 12px 8px 8px; }
.icon { width: 44px; height: 44px; border: none; background: none; display: flex; align-items: center; justify-content: center; border-radius: 22px; flex-shrink: 0; }
.icon.round { background: var(--c-surface); box-shadow: var(--shadow-soft); }
.icon.primary { background: var(--c-primary); box-shadow: 0 2px 8px rgba(108,92,231,.25); }
.title-wrap { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.name { font-size: 20px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sub { display: flex; align-items: center; gap: 5px; font-size: 12px; color: var(--c-muted); white-space: nowrap; }
.sub b { color: var(--c-live-ink); }
.dot { width: 6px; height: 6px; border-radius: 3px; background: var(--c-live); }

.stage { position: relative; margin: 0 12px; border-radius: 28px; background: #E9E4FF; overflow: hidden; flex-shrink: 0; cursor: pointer; }
.deco { position: absolute; pointer-events: none; }
.ground { left: 10%; top: 70px; width: 80%; height: 190px; border-radius: 50%; background: #F5F2FF; }
.pad { left: calc(50% - 43px); top: 140px; width: 86px; height: 44px; border-radius: 50%; background: #D9D1FF; }
.tree { display: flex; flex-direction: column; align-items: center; }
.tree div:first-child { border-radius: 50%; background: #A8E6CF; }
.tree div:last-child { background: #C9A98E; border-radius: 2px; }
.t1 { left: 14px; top: 22px; } .t1 div:first-child { width: 44px; height: 44px; } .t1 div:last-child { width: 8px; height: 16px; }
.t2 { right: 16px; top: 200px; } .t2 div:first-child { width: 38px; height: 38px; } .t2 div:last-child { width: 7px; height: 14px; }
.bench { right: 30px; top: 26px; width: 56px; height: 14px; border-radius: 7px; background: #fff; box-shadow: 0 10px 0 -4px #C9A98E; }

.avatar {
    position: absolute; width: 64px; border: none; background: none; padding: 0;
    display: flex; flex-direction: column; align-items: center; gap: 2px;
    transition: left .6s ease, top .6s ease; /* 이동을 부드럽게 */
}
.bubble { background: #fff; border-radius: 10px; padding: 3px 8px; font-size: 11px; font-weight: 700; color: var(--c-ink); white-space: nowrap; box-shadow: 0 2px 6px rgba(31,27,58,.10); }
.face { width: 46px; height: 46px; border-radius: 23px; display: flex; align-items: center; justify-content: center; border: 3px solid transparent; }
.face.me { border-color: var(--c-primary); }
.tag { background: rgba(31,27,58,.72); color: #fff; border-radius: 8px; padding: 1px 6px; font-size: 10px; font-weight: 700; white-space: nowrap; max-width: 80px; overflow: hidden; text-overflow: ellipsis; }
.alone { position: absolute; left: 0; right: 0; bottom: 14px; margin: 0; text-align: center; font-size: 12px; color: var(--c-muted); pointer-events: none; }

.card { position: absolute; left: 10px; right: 10px; bottom: 10px; background: var(--c-surface); border-radius: 20px; padding: 12px; box-shadow: 0 8px 24px rgba(31,27,58,.18); display: flex; flex-direction: column; gap: 10px; cursor: default; }
.card-head { display: flex; align-items: center; gap: 8px; }
.card-name { flex: 1; display: flex; align-items: center; gap: 8px; font-size: 15px; }
.card-hearts { display: inline-flex; align-items: center; gap: 3px; font-size: 12px; font-weight: 700; color: #D63E5C; }
.mini { width: 32px; height: 32px; border: none; background: var(--c-primary-soft); border-radius: 16px; display: flex; align-items: center; justify-content: center; }
.photos { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; }
.ph { height: 64px; border-radius: 12px; background: #EEEBFA; border: none; padding: 0; overflow: hidden; }
.ph img { width: 100%; height: 100%; object-fit: cover; display: block; }
.ph.skeleton { animation: pulse 1s infinite alternate; }
@keyframes pulse { to { opacity: .5; } }
.solid.muted { background: #ECE8FF; color: var(--c-primary-deep); }
.ph.locked { background: #E6E1FF; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; font-size: 11px; font-weight: 700; color: var(--c-primary-deep); }
.card-actions { display: flex; gap: 6px; }
.card-actions button { flex: 1; height: 40px; border-radius: 12px; font-size: 14px; font-weight: 700; }
.solid { border: none; background: var(--c-primary); color: #fff; }
.line { border: 1.5px solid var(--c-primary-line); background: var(--c-surface); color: var(--c-primary-ink); }

.chat { flex: 1; min-height: 0; margin-top: 10px; background: var(--c-surface); border-radius: 28px 28px 0 0; box-shadow: 0 -4px 16px rgba(108,92,231,.06); display: flex; flex-direction: column; }
.msgs { flex: 1; min-height: 0; padding: 14px 16px 8px; display: flex; flex-direction: column; gap: 10px; }
.sys { align-self: center; font-size: 11px; color: var(--c-muted); background: var(--c-bg); padding: 4px 10px; border-radius: 10px; }
.other { display: flex; gap: 8px; align-items: flex-start; }
.col { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.who { font-size: 11px; color: var(--c-muted); font-weight: 700; }
.other .b { background: var(--c-primary-soft); border-radius: 4px 16px 16px 16px; padding: 8px 12px; font-size: 14px; max-width: 230px; word-break: break-word; }
.mine { align-self: flex-end; display: flex; flex-direction: column; align-items: flex-end; gap: 3px; }
.mine .b { background: var(--c-primary); color: #fff; border-radius: 16px 4px 16px 16px; padding: 8px 12px; font-size: 14px; max-width: 230px; word-break: break-word; }
.time { font-size: 10px; color: var(--c-muted); }

.compose { display: flex; align-items: center; gap: 8px; padding: 10px 12px 22px; border-top: 1px solid var(--c-divider); }
.emote, .send { width: 44px; height: 44px; border-radius: 22px; border: none; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.emote { background: var(--c-primary-soft); }
.send { background: var(--c-primary); }
.send:disabled { opacity: .5; }
.field { flex: 1; height: 44px; background: var(--c-bg); border-radius: 22px; display: flex; align-items: center; padding: 0 16px; position: relative; }
.field input { flex: 1; min-width: 0; border: none; outline: none; background: transparent; font-size: 14px; color: var(--c-ink); }

.dim { position: absolute; inset: 0; background: rgba(31,27,58,.45); display: flex; align-items: flex-end; z-index: 20; }
.sheet { width: 100%; max-height: 70%; background: var(--c-surface); border-radius: 28px 28px 0 0; padding: 10px 20px 30px; display: flex; flex-direction: column; gap: 12px; }
.grip { align-self: center; width: 40px; height: 5px; border-radius: 3px; background: var(--c-line); }
.sheet-title { font-size: 16px; }
.list { display: flex; flex-direction: column; gap: 4px; }
.person { display: flex; align-items: center; gap: 10px; border: none; background: none; padding: 8px 4px; font-size: 15px; font-weight: 700; color: var(--c-ink); text-align: left; border-radius: 12px; }
.person small { color: var(--c-muted); font-weight: 500; }
</style>
