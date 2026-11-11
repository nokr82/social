<script setup>
/**
 * 동네 상시 광장 내부 (디자인: TownPlaza.dc.html)
 * 위: 광장 지도 = 구역 5개 / 가운데: 선택한 구역 풍경 / 아래: 구역 채팅
 *
 * 구조
 *  - 입장: POST /plazas/{id}/town/enter → 서버가 구역(안 고르면 가장 북적이는 곳)·채널(30명 단위) 배정
 *  - 같은 구역·채널 사람끼리만 보임: presence 채널 town.{id}.{구역}.{채널}
 *  - 구역 탭·채널 바꾸기 = enter를 다시 호출 (서버가 이전 자리에서 빼줌)
 *  - 30초마다 heartbeat, 20초마다 지도 숫자 갱신, 화면을 떠나면 leave
 */
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import AvatarFace from '../components/AvatarFace.vue';
import { ApiError, api, get, post } from '../lib/api';
import { auth } from '../lib/auth';
import { connectEcho } from '../lib/echo';
import { showToast } from '../lib/toast';

const props = defineProps({ id: { type: String, required: true } });
const router = useRouter();

// 구역 버튼 위치·풍경 색 (디자인 시안 값). 구역 목록·이름·주제는 서버(config social.town.zones)가 기준
const ZONE_LOOK = {
    fountain: { mx: 150, my: 38, ground: '#DCE6FF' },
    bench: { mx: 34, my: 26, ground: '#E6F8EF' },
    playground: { mx: 266, my: 26, ground: '#FFE4EC' },
    foodtruck: { mx: 50, my: 70, ground: '#FFF0D6' },
    field: { mx: 246, my: 70, ground: '#E2F3FF' },
};
// 풍경 안 아바타 자리 10개 (디자인 배치 기반). 넘치면 '+N명 더 있어요'
const SLOTS = [
    { x: 30, y: 80 }, { x: 84, y: 64 }, { x: 140, y: 96 }, { x: 196, y: 70 }, { x: 252, y: 92 },
    { x: 300, y: 66 }, { x: 110, y: 134 }, { x: 220, y: 130 }, { x: 60, y: 136 }, { x: 166, y: 138 },
];
const EMOTES = [
    { code: 'wave', e: '👋', label: '손 흔들기' },
    { code: 'laugh', e: '😂', label: '웃기' },
    { code: 'heart', e: '❤️', label: '하트' },
    { code: 'clap', e: '👏', label: '박수' },
    { code: 'wow', e: '😮', label: '놀람' },
    { code: 'party', e: '🎉', label: '축하' },
];
const EMOTE_MAP = Object.fromEntries(EMOTES.map((x) => [x.code, x.e]));

const state = ref(null);              // GET /plazas/{id}/town {name, live_count, channel_capacity, zones[]}
const zone = ref(null);               // 지금 있는 구역 key
const channel = ref(null);            // 지금 있는 채널 번호
const members = ref([]);              // 같은 구역·채널 접속자 (presence)
const messages = ref([]);             // 오래된 → 최신
const blocked = ref(new Set());
const bubbles = reactive({});         // userId → 말풍선 (5초)
const emotes = reactive({});          // userId → 표정 코드 (2.5초)
const draft = ref('');
const sending = ref(false);
const moving = ref(false);            // 구역·채널 이동 중 (중복 탭 방지)
const hasOlder = ref(true);
const showEmotes = ref(false);
const showChannels = ref(false);
const chatEl = ref(null);

let presenceName = null;              // 지금 구독 중인 presence 채널 이름
let channelRef = null;
let heartbeatTimer = null;
let stateTimer = null;
let left = false;
const timers = [];

const meId = computed(() => auth.me?.id);
const cur = computed(() => state.value?.zones.find((z) => z.key === zone.value) ?? null);
const curChannel = computed(() => cur.value?.channels.find((c) => c.no === channel.value) ?? { no: channel.value, count: members.value.length });

/** 지도 구역 버튼 */
const zones = computed(() => (state.value?.zones ?? []).map((z) => ({ ...z, ...ZONE_LOOK[z.key], on: z.key === zone.value })));

/** 풍경 아바타: 같은 채널 사람 (차단 제외), 모든 화면에서 같은 순서(id순)로 자리 배정 */
const visible = computed(() => members.value
    .filter((m) => !blocked.value.has(m.id))
    .sort((a, b) => a.id - b.id));
const sceneAvatars = computed(() => visible.value.slice(0, SLOTS.length).map((m, i) => ({
    ...m, ...SLOTS[i], me: m.id === meId.value, bubble: bubbles[m.id], emote: EMOTE_MAP[emotes[m.id]],
})));
const more = computed(() => Math.max(0, visible.value.length - SLOTS.length));

/* ---------------- 입장 · 이동 ---------------- */

/** 입장 또는 구역·채널 이동. 서버가 자리를 배정하고 이전 자리에서 빼준다 */
async function enterAt(z = null, ch = null) {
    if (moving.value) return;
    moving.value = true;
    try {
        const res = await post(`/plazas/${props.id}/town/enter`, { zone: z, channel: ch });
        if (left) return; // 응답 오기 전에 화면을 떠났으면 무시
        state.value = res.state;
        const changed = res.zone !== zone.value || res.channel !== channel.value;
        zone.value = res.zone;
        channel.value = res.channel;
        if (changed || !channelRef) {
            joinPresence(res.presence_channel);
            await loadMessages();
        }
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '광장에 들어갈 수 없어요');
        if (!zone.value) router.replace({ name: 'main' }); // 첫 입장 실패면 메인으로
    } finally {
        moving.value = false;
    }
}

function pickZone(key) {
    if (key !== zone.value) enterAt(key);
}

function pickChannel(c) {
    showChannels.value = false;
    if (c.no === channel.value) return;
    if (c.full) return showToast('이 채널은 가득 찼어요');
    enterAt(zone.value, c.no);
}

/** presence 채널 교체: 이전 구역·채널 구독을 끊고 새로 구독 */
function joinPresence(name) {
    if (presenceName) window.Echo?.leave(presenceName);
    members.value = [];
    presenceName = name;
    channelRef = connectEcho().join(name)
        .here((users) => { members.value = users; })
        .joining((user) => {
            if (!members.value.some((m) => m.id === user.id)) members.value.push(user);
        })
        .leaving((user) => { members.value = members.value.filter((m) => m.id !== user.id); })
        .listen('.message.sent', (msg) => {
            if (blocked.value.has(msg.sender?.id)) return;
            appendMessage(msg);
        })
        .listenForWhisper('emote', (e) => {
            if (typeof e?.id === 'number' && EMOTE_MAP[e.code] && !blocked.value.has(e.id)) showEmote(e.id, e.code);
        })
        .error(() => showToast('실시간 연결에 문제가 있어요. 잠시 후 다시 들어와 주세요'));
}

async function heartbeat() {
    if (!zone.value) return;
    try {
        await post(`/plazas/${props.id}/town/heartbeat`, { zone: zone.value, channel: channel.value });
    } catch (e) {
        // 네트워크가 끊겼다 돌아와 이탈 처리된 경우 → 같은 자리로 다시 (꽉 찼으면 같은 구역 다른 채널)
        if (e instanceof ApiError && e.code === 'NOT_IN_PLAZA') {
            const z = zone.value;
            await post(`/plazas/${props.id}/town/enter`, { zone: z, channel: channel.value })
                .catch(() => enterAt(z));
        }
    }
}

async function refreshState() {
    try { state.value = (await get(`/plazas/${props.id}/town`)).data; } catch { /* 다음 주기에 다시 */ }
}

function leave() {
    left = true;
    clearInterval(heartbeatTimer);
    clearInterval(stateTimer);
    timers.forEach(clearTimeout);
    if (presenceName) window.Echo?.leave(presenceName);
    if (zone.value) {
        // 화면을 떠나는 중에도 요청이 끝나도록 keepalive
        api('POST', `/plazas/${props.id}/town/leave`, { zone: zone.value, channel: channel.value }, { keepalive: true }).catch(() => {});
    }
}

/* ---------------- 구역 채팅 ---------------- */

async function loadMessages() {
    const z = zone.value;
    const ch = channel.value;
    const res = await get(`/plazas/${props.id}/town/messages?zone=${z}&channel=${ch}`);
    if (z !== zone.value || ch !== channel.value) return; // 그 사이 다른 구역으로 옮겼으면 버림
    messages.value = res.data.reverse();
    hasOlder.value = res.data.length >= 50;
    scrollToBottom();
}

async function onChatScroll() {
    const el = chatEl.value;
    if (!el || el.scrollTop > 20 || !hasOlder.value) return;
    const oldest = messages.value.find((m) => typeof m.id === 'number');
    if (!oldest) return;
    hasOlder.value = false; // 중복 요청 방지
    const prevHeight = el.scrollHeight;
    const res = await get(`/plazas/${props.id}/town/messages?zone=${zone.value}&channel=${channel.value}&before_id=${oldest.id}`);
    messages.value = [...res.data.reverse(), ...messages.value];
    hasOlder.value = res.data.length >= 50;
    await nextTick();
    el.scrollTop = el.scrollHeight - prevHeight; // 보던 위치 유지
}

async function send() {
    const body = draft.value.trim();
    if (!body || sending.value || !zone.value) return;
    sending.value = true;
    try {
        const res = await post(`/plazas/${props.id}/town/messages`, { zone: zone.value, channel: channel.value, body });
        draft.value = '';
        appendMessage(res.data);
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '메시지를 보내지 못했어요');
        if (e instanceof ApiError && e.code === 'NOT_IN_PLAZA') enterAt(zone.value);
    } finally {
        sending.value = false;
    }
}

function appendMessage(msg) {
    if (messages.value.some((m) => m.id === msg.id)) return;
    messages.value.push(msg);
    showBubble(msg.sender?.id, msg.body);
    scrollToBottom();
}

function showBubble(userId, text) {
    if (!userId || !text) return;
    bubbles[userId] = text.length > 12 ? `${text.slice(0, 12)}…` : text;
    timers.push(setTimeout(() => { if (bubbles[userId]) delete bubbles[userId]; }, 5000));
}

let lastEmoteAt = 0;
function sendEmote(code) {
    const now = Date.now();
    if (now - lastEmoteAt < 1200) return; // 연타 방지
    lastEmoteAt = now;
    showEmote(meId.value, code);
    channelRef?.whisper('emote', { id: meId.value, code });
}

function showEmote(userId, code) {
    delete emotes[userId];
    requestAnimationFrame(() => {
        emotes[userId] = code;
        timers.push(setTimeout(() => delete emotes[userId], 2500));
    });
}

async function scrollToBottom() {
    await nextTick();
    if (chatEl.value) chatEl.value.scrollTop = chatEl.value.scrollHeight;
}

function openProfile(a) {
    if (!a.me) router.push({ name: 'profile', params: { id: a.id }, query: { from: props.id } });
}

onMounted(async () => {
    if (!auth.me) {
        try { auth.me = (await get('/me')).data; } catch { /* 401은 api.js가 처리 */ }
    }
    blocked.value = new Set((await get('/blocks').catch(() => ({ user_ids: [] }))).user_ids);
    await enterAt(); // 구역·채널은 서버가 배정
    heartbeatTimer = setInterval(heartbeat, 30_000);
    stateTimer = setInterval(refreshState, 20_000);
});
onBeforeUnmount(leave);
</script>

<template>
    <div class="town">
        <!-- 헤더 -->
        <div class="head">
            <button class="icon" aria-label="뒤로가기 (메인)" @click="router.push({ name: 'main' })">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
            </button>
            <div class="title-wrap">
                <h1 class="title name">{{ state?.name ?? ' ' }}</h1>
                <span class="sub">
                    <span class="always">상시</span>
                    <i class="dot" />
                    <b>{{ state?.live_count ?? 0 }}명 접속중</b>
                </span>
            </div>
            <button class="icon round primary" aria-label="이 광장에 번개 만들기" @click="router.push({ name: 'meetup-create', params: { plazaId: id } })">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2L4 14h7l-1 8 9-12h-7z" /></svg>
            </button>
        </div>

        <!-- 광장 지도: 구역 선택 -->
        <div class="map" role="group" aria-label="구역 선택">
            <div class="map-ground" aria-hidden="true" />
            <button
                v-for="z in zones" :key="z.key" type="button" class="zone" :class="{ on: z.on }"
                :style="{ left: `${z.mx}px`, top: `${z.my}px` }"
                :aria-pressed="z.on" :aria-label="`${z.name} 구역, ${z.count}명${z.on ? ' (현재 위치)' : ''}`"
                :disabled="moving" @click="pickZone(z.key)"
            >
                <span class="zn">{{ z.name }}</span>
                <span class="zc">{{ z.count }}명</span>
            </button>
        </div>

        <!-- 선택한 구역 풍경 -->
        <div class="scene" :style="{ background: ZONE_LOOK[zone]?.ground ?? '#E9E4FF' }">
            <div class="scene-top">
                <span class="scene-label">{{ cur?.name ?? '…' }} <span>· {{ cur?.topic }}</span></span>
                <button type="button" class="ch-btn" aria-label="채널 바꾸기" :disabled="!cur || moving" @click="showChannels = true">
                    {{ channel ?? 1 }}채널 {{ curChannel.count }}/{{ state?.channel_capacity ?? 30 }}
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
                </button>
            </div>
            <div class="scene-pad" aria-hidden="true" />
            <button
                v-for="a in sceneAvatars" :key="a.id" type="button" class="av"
                :style="{ left: `${a.x}px`, top: `${a.y - (a.bubble ? 18 : 0)}px` }"
                :aria-label="a.me ? '내 아바타' : `${a.nickname} 프로필 보기`" @click="openProfile(a)"
            >
                <span v-if="a.emote" class="emote-pop" aria-hidden="true">{{ a.emote }}</span>
                <span v-if="a.bubble" class="bubble">{{ a.bubble }}</span>
                <span class="face" :class="{ me: a.me, bounce: a.emote }"><AvatarFace :parts="a.avatar" :size="38" /></span>
                <span class="tag">{{ a.me ? '나' : a.nickname }}</span>
            </button>
            <p v-if="visible.length <= 1" class="alone">아직 혼자예요. 다른 구역도 둘러보세요!</p>
            <span v-if="more" class="more">+{{ more }}명 더 있어요</span>
        </div>

        <!-- 구역 채팅 -->
        <div class="chat">
            <div ref="chatEl" class="msgs scroll" aria-live="polite" @scroll.passive="onChatScroll">
                <div v-if="cur" class="sys">{{ cur.name }} {{ channel }}채널에 들어왔어요 · 이 구역 사람들과만 대화해요</div>
                <template v-for="m in messages" :key="m.id">
                    <div v-if="m.sender?.id === meId" class="mine"><span class="b">{{ m.body }}</span></div>
                    <div v-else class="other">
                        <AvatarFace :parts="m.sender?.avatar" :size="30" variant="mini" style="flex-shrink: 0" />
                        <div class="col">
                            <span class="who">{{ m.sender?.nickname }}</span>
                            <span class="b">{{ m.body }}</span>
                        </div>
                    </div>
                </template>
            </div>

            <div v-if="showEmotes" class="emote-bar" role="toolbar" aria-label="표정·동작">
                <button v-for="x in EMOTES" :key="x.code" type="button" :aria-label="x.label" @click="sendEmote(x.code)">{{ x.e }}</button>
            </div>
            <form class="compose" @submit.prevent="send">
                <button type="button" class="emote" :class="{ active: showEmotes }" aria-label="아바타 표정·동작" :aria-expanded="showEmotes" @click="showEmotes = !showEmotes">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#5B4BD6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M8.5 14.5q3.5 3 7 0" /><path d="M9 9.5h.01M15 9.5h.01" /></svg>
                </button>
                <label class="field">
                    <span class="sr-only">메시지 입력</span>
                    <input v-model="draft" type="text" maxlength="500" :placeholder="`${cur?.name ?? '광장'}에 한마디…`" enterkeyhint="send">
                </label>
                <button type="submit" class="send" aria-label="보내기" :disabled="!draft.trim() || sending">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                </button>
            </form>
        </div>

        <!-- 채널 바꾸기 시트 -->
        <div v-if="showChannels" class="dim" @click.self="showChannels = false">
            <div class="sheet" role="dialog" aria-modal="true" aria-label="채널 바꾸기">
                <div class="grip" />
                <b class="sheet-title">{{ cur?.name }} 채널</b>
                <p class="sheet-desc">같은 채널 사람들끼리만 보이고 대화해요. 한 채널은 최대 {{ state?.channel_capacity }}명이에요.</p>
                <div class="ch-list">
                    <button
                        v-for="c in cur?.channels ?? []" :key="c.no" type="button" class="ch-item"
                        :class="{ on: c.no === channel }" :disabled="c.full && c.no !== channel" @click="pickChannel(c)"
                    >
                        <b>{{ c.no }}채널</b>
                        <span>{{ c.no === channel ? '지금 여기 · ' : '' }}{{ c.count }}/{{ state?.channel_capacity }}명{{ c.full ? ' · 가득 참' : '' }}</span>
                    </button>
                </div>
                <button type="button" class="sheet-cancel" @click="showChannels = false">닫기</button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.town { height: 100%; display: flex; flex-direction: column; position: relative; }

.head { display: flex; align-items: center; gap: 8px; padding: 14px 12px 6px 8px; }
.icon { width: 44px; height: 44px; border: none; background: none; display: flex; align-items: center; justify-content: center; border-radius: 22px; flex-shrink: 0; }
.icon.round { box-shadow: var(--shadow-soft); }
.icon.primary { background: var(--c-primary); box-shadow: 0 2px 8px rgba(108,92,231,.25); }
.title-wrap { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.name { font-size: 21px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sub { display: flex; align-items: center; gap: 5px; font-size: 12px; color: var(--c-muted); white-space: nowrap; }
.sub b { color: var(--c-live-ink); }
.always { background: #ECE8FF; color: var(--c-primary-deep); font-weight: 700; padding: 1px 6px; border-radius: 6px; }
.dot { width: 6px; height: 6px; border-radius: 3px; background: var(--c-live); }

/* 광장 지도 */
.map { margin: 4px 12px 0; position: relative; height: 118px; border-radius: 22px; background: #E9E4FF; overflow: hidden; flex-shrink: 0; }
.map-ground { position: absolute; left: 20px; top: 14px; right: 20px; height: 90px; border-radius: 50%; background: #F5F2FF; }
.zone {
    position: absolute; min-width: 60px; height: 44px; padding: 0 10px; border-radius: 22px;
    border: 2px solid #fff; background: #fff; color: var(--c-ink);
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    box-shadow: 0 2px 6px rgba(31,27,58,.10);
}
.zone.on { border-color: var(--c-primary); background: var(--c-primary); color: #fff; }
.zone:disabled { cursor: default; }
.zn { font-size: 12px; font-weight: 700; line-height: 1.2; }
.zc { font-size: 10px; font-weight: 700; line-height: 1.2; opacity: .85; }

/* 구역 풍경 */
.scene { margin: 10px 12px 0; position: relative; height: 196px; border-radius: 24px; overflow: hidden; flex-shrink: 0; transition: background .3s; }
.scene-top { position: absolute; left: 12px; top: 12px; right: 12px; display: flex; justify-content: space-between; align-items: center; z-index: 2; }
.scene-label { background: rgba(255,255,255,.92); border-radius: 12px; padding: 4px 10px; font-size: 13px; font-weight: 700; }
.scene-label span { font-weight: 500; color: var(--c-muted); }
.ch-btn { height: 32px; border: none; background: rgba(31,27,58,.78); color: #fff; border-radius: 16px; padding: 0 10px; font-size: 12px; font-weight: 700; display: flex; align-items: center; gap: 4px; }
.scene-pad { position: absolute; left: 40px; top: 70px; right: 40px; height: 110px; border-radius: 50%; background: rgba(255,255,255,.45); }
.av { position: absolute; border: none; background: none; padding: 0; display: flex; flex-direction: column; align-items: center; gap: 1px; z-index: 1; transition: top .2s; }
.face { position: relative; display: flex; border-radius: 50%; border: 2px solid transparent; }
.face.me { border-color: var(--c-primary); }
.tag { background: rgba(31,27,58,.72); color: #fff; border-radius: 6px; padding: 0 5px; font-size: 9px; font-weight: 700; white-space: nowrap; max-width: 70px; overflow: hidden; text-overflow: ellipsis; }
.bubble { background: #fff; border-radius: 10px; padding: 3px 8px; font-size: 11px; font-weight: 700; white-space: nowrap; box-shadow: 0 2px 6px rgba(31,27,58,.10); margin-bottom: 2px; }
.emote-pop { position: absolute; top: -26px; left: 50%; font-size: 24px; transform: translateX(-50%); animation: emote-up 2.5s ease-out forwards; pointer-events: none; }
@keyframes emote-up {
    0% { opacity: 0; transform: translate(-50%, 8px) scale(.4); }
    15% { opacity: 1; transform: translate(-50%, 0) scale(1.2); }
    25% { transform: translate(-50%, 0) scale(1); }
    80% { opacity: 1; }
    100% { opacity: 0; transform: translate(-50%, -18px); }
}
.face.bounce { animation: bounce .5s ease-out 2; }
@keyframes bounce { 0%, 100% { transform: translateY(0); } 40% { transform: translateY(-8px); } }
@media (prefers-reduced-motion: reduce) { .emote-pop, .face.bounce { animation: none; } }
.alone { position: absolute; left: 0; right: 0; bottom: 14px; margin: 0; text-align: center; font-size: 12px; color: var(--c-muted); pointer-events: none; }
.more { position: absolute; right: 12px; bottom: 10px; background: rgba(255,255,255,.92); border-radius: 10px; padding: 3px 9px; font-size: 11px; font-weight: 700; color: var(--c-primary-deep); z-index: 2; }

/* 구역 채팅 */
.chat { flex: 1; min-height: 0; margin-top: 10px; background: var(--c-surface); border-radius: 28px 28px 0 0; box-shadow: 0 -4px 16px rgba(108,92,231,.06); display: flex; flex-direction: column; }
.msgs { flex: 1; min-height: 0; padding: 14px 16px 8px; display: flex; flex-direction: column; gap: 10px; }
.sys { align-self: center; font-size: 11px; color: var(--c-muted); background: var(--c-bg); padding: 4px 10px; border-radius: 10px; text-align: center; }
.other { display: flex; gap: 8px; align-items: flex-start; }
.other .col { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.who { font-size: 11px; color: var(--c-muted); font-weight: 700; }
.other .b { background: var(--c-primary-soft); border-radius: 4px 16px 16px 16px; padding: 8px 12px; font-size: 14px; max-width: 250px; word-break: break-word; }
.mine { align-self: flex-end; }
.mine .b { display: inline-block; background: var(--c-primary); color: #fff; border-radius: 16px 4px 16px 16px; padding: 8px 12px; font-size: 14px; max-width: 250px; word-break: break-word; }

.emote-bar { display: flex; justify-content: space-around; padding: 8px 12px 0; }
.emote-bar button { width: 46px; height: 46px; border: none; border-radius: 23px; background: var(--c-bg); font-size: 24px; display: flex; align-items: center; justify-content: center; }
.compose { display: flex; align-items: center; gap: 8px; padding: 10px 12px 22px; border-top: 1px solid var(--c-divider); }
.emote { width: 44px; height: 44px; border-radius: 22px; border: none; background: var(--c-primary-soft); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.emote.active { box-shadow: inset 0 0 0 2px var(--c-primary); }
.field { flex: 1; height: 44px; background: var(--c-bg); border-radius: 22px; display: flex; align-items: center; padding: 0 16px; min-width: 0; }
.field input { flex: 1; min-width: 0; border: none; outline: none; background: transparent; font-size: 14px; color: var(--c-ink); }
.send { width: 44px; height: 44px; border-radius: 22px; border: none; background: var(--c-primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.send:disabled { background: var(--c-line); }

/* 채널 시트 */
.dim { position: absolute; inset: 0; background: rgba(31,27,58,.45); display: flex; align-items: flex-end; z-index: 20; }
.sheet { width: 100%; max-height: 70%; background: var(--c-surface); border-radius: 28px 28px 0 0; padding: 10px 20px 30px; display: flex; flex-direction: column; gap: 12px; }
.grip { align-self: center; width: 40px; height: 5px; border-radius: 3px; background: var(--c-line); }
.sheet-title { font-size: 16px; }
.sheet-desc { margin: -4px 0 0; font-size: 13px; color: var(--c-muted); line-height: 1.5; }
.ch-list { display: flex; flex-direction: column; gap: 6px; overflow-y: auto; }
.ch-item { display: flex; justify-content: space-between; align-items: center; height: 52px; padding: 0 16px; border-radius: 14px; border: 1.5px solid var(--c-line); background: var(--c-surface); font-size: 15px; color: var(--c-ink); }
.ch-item span { font-size: 13px; color: var(--c-muted); }
.ch-item.on { border-color: var(--c-primary); background: var(--c-primary-soft); }
.ch-item:disabled { opacity: .5; }
.sheet-cancel { height: 44px; border: none; background: none; color: var(--c-muted); font-size: 14px; font-weight: 700; }
</style>
