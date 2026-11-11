<script setup>
/**
 * 번개 상세 (디자인: Bungae.dc.html)
 * - 정확한 장소는 참여 확정 후 공개 (서버가 참여자에게만 place_detail을 내려줌)
 * - 나이 조건은 서버가 확인만, 참여자 나이는 비공개
 * - 안전 안내 상시 노출 · 참여 → 번개 채팅방
 */
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import AvatarFace from '../components/AvatarFace.vue';
import { ApiError, del, get, post } from '../lib/api';
import { meetupWhen, until } from '../lib/time';
import { showToast } from '../lib/toast';

const props = defineProps({ id: { type: String, required: true } });
const router = useRouter();

const m = ref(null);
const busy = ref(false);
const confirmCancel = ref(false);

const joined = computed(() => ['joined', 'host'].includes(m.value?.my_status));
const full = computed(() => m.value && m.value.joined_count >= m.value.capacity);
// 6칸 슬롯 (디자인) — 정원만큼 (최대 10), 빈자리는 점선
const slots = computed(() => Array.from({ length: m.value?.capacity ?? 0 }, (_, i) => m.value.members[i] ?? null));
// 1시간 이내면 늦은 취소 안내
const lateCancel = computed(() => m.value && new Date(m.value.starts_at).getTime() - Date.now() < 60 * 60 * 1000);

async function load() {
    try {
        m.value = (await get(`/meetups/${props.id}`)).data;
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '번개를 찾을 수 없어요');
        router.back();
    }
}

async function act(fn) {
    if (busy.value) return;
    busy.value = true;
    try { await fn(); } catch (e) {
        showToast(e instanceof ApiError ? e.message : '잠시 후 다시 시도해 주세요');
    } finally { busy.value = false; }
}

const join = () => act(async () => {
    m.value = (await post(`/meetups/${props.id}/join`)).data;
    showToast('참여 확정! 정확한 장소가 공개됐어요');
});
const leave = () => act(async () => {
    const res = await post(`/meetups/${props.id}/cancel`);
    confirmCancel.value = false;
    showToast(res.status === 'late_canceled' ? '취소했어요 (시작 1시간 이내라 늦은 취소로 기록돼요)' : '참여를 취소했어요');
    await load();
});
const cancelMeetup = () => act(async () => {
    await del(`/meetups/${props.id}`);
    confirmCancel.value = false;
    showToast('번개를 취소했어요. 참여자에게 안내됐어요');
    await load();
});

function openReport() {
    const h = m.value.host;
    router.push({ name: 'report', params: { userId: h.id }, query: { nickname: h.nickname, color: h.avatar?.color, face: h.avatar?.face } });
}

onMounted(load);
</script>

<template>
    <div class="bg">
        <div class="head">
            <button class="icon" aria-label="뒤로가기" @click="router.back()">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
            </button>
            <span class="head-title">번개</span>
            <button v-if="m && m.my_status !== 'host'" class="icon" aria-label="더보기 (신고)" @click="openReport">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="#1F1B3A" aria-hidden="true"><circle cx="5" cy="12" r="2" /><circle cx="12" cy="12" r="2" /><circle cx="19" cy="12" r="2" /></svg>
            </button>
            <span v-else class="icon" />
        </div>

        <div v-if="m" class="body scroll">
            <!-- 제목 카드 -->
            <div class="hero">
                <span class="deco" aria-hidden="true" />
                <span class="when-chip">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2L4 14h7l-1 8 9-12h-7z" /></svg>
                    {{ m.status === 'canceled' ? '취소된 번개' : `${meetupWhen(m.starts_at).split(' ')[0]} · ${until(m.starts_at)}` }}
                </span>
                <h1 class="title" style="font-size: 26px">{{ m.title }}</h1>
                <span class="host-line">
                    <AvatarFace :parts="m.host.avatar" :size="26" variant="mini" />
                    <span><b>{{ m.host.nickname }}</b> 호스트 · {{ m.plaza.name }}</span>
                </span>
            </div>

            <!-- 정보 -->
            <div class="info">
                <div class="row">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#5B4BD6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>
                    <span class="k">시간</span><b>{{ meetupWhen(m.starts_at) }}</b>
                </div>
                <div class="row top">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#5B4BD6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z" /><circle cx="12" cy="9.5" r="2.5" /></svg>
                    <span class="k">장소</span>
                    <span class="v">
                        <b>{{ m.place_detail ?? m.area_label }}</b>
                        <small :class="{ ok: m.place_detail }">{{ m.place_detail ? '참여 확정! 정확한 장소가 공개됐어요' : '정확한 장소는 참여 확정 후 공개돼요' }}</small>
                    </span>
                </div>
                <div class="row">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#5B4BD6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3.5" /><path d="M2.5 20a6.5 6.5 0 0 1 13 0" /><path d="M16 4.5a3.5 3.5 0 0 1 0 7" /><path d="M18 14.5a6.5 6.5 0 0 1 3.5 5.5" /></svg>
                    <span class="k">인원</span><b>{{ m.joined_count }}/{{ m.capacity }}명</b>
                </div>
                <div class="row top last">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#5B4BD6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z" /><path d="M9 12l2 2 4-4" /></svg>
                    <span class="k">조건</span>
                    <span class="v"><b>{{ m.condition }}</b><small>나이는 서버가 확인만 하고 공개하지 않아요</small></span>
                </div>
            </div>

            <!-- 참여자 -->
            <section class="sec">
                <h2>참여자 {{ m.joined_count }}명</h2>
                <div class="slots">
                    <div v-for="(s, i) in slots" :key="i" class="slot">
                        <span v-if="s" class="face" :class="{ me: s.is_me }"><AvatarFace :parts="s.avatar" :size="40" /></span>
                        <span v-else class="empty" aria-hidden="true" />
                        <span class="nm">{{ s ? (s.is_host ? `${s.nickname}★` : s.nickname) : '빈자리' }}</span>
                    </div>
                </div>
            </section>

            <div v-if="m.description" class="desc">{{ m.description }}</div>

            <div class="safety">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#9A6200" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex-shrink: 0; margin-top: 2px"><path d="M12 3l9 16H3z" /><path d="M12 10v4M12 17h.01" /></svg>
                <span>처음 만나는 자리예요. 사람 많은 공공장소에서 만나고, 연락처·주소 같은 개인정보는 천천히 공유하세요. 불편한 일이 있으면 바로 신고해 주세요.</span>
            </div>
        </div>

        <!-- 하단 버튼 -->
        <div v-if="m" class="bottom">
            <template v-if="m.status === 'canceled'">
                <button class="btn-primary" disabled>취소된 번개예요</button>
            </template>
            <template v-else-if="joined">
                <button v-if="m.is_upcoming" class="ghost" @click="confirmCancel = true">{{ m.my_status === 'host' ? '번개 취소' : '참여 취소' }}</button>
                <button class="btn-primary grow" @click="router.push({ name: 'meetup-room', params: { id: m.id } })">번개 채팅방 열기</button>
            </template>
            <button v-else-if="!m.is_upcoming" class="btn-primary" disabled>이미 시작한 번개예요</button>
            <button v-else class="btn-primary" :disabled="busy || full" @click="join">{{ full ? '정원이 찼어요' : '참여하기' }}</button>
        </div>

        <!-- 취소 확인 -->
        <div v-if="confirmCancel" class="dim" @click.self="confirmCancel = false">
            <div class="confirm" role="alertdialog">
                <h2>{{ m.my_status === 'host' ? '번개를 취소할까요?' : '참여를 취소할까요?' }}</h2>
                <p v-if="m.my_status === 'host'">참여자 모두에게 취소 안내가 가고, 채팅방이 닫혀요.</p>
                <p v-else-if="lateCancel">시작 1시간 이내라 <b>늦은 취소</b>로 기록돼요.</p>
                <p v-else>시작 1시간 전까지는 부담 없이 취소할 수 있어요.</p>
                <div class="btns">
                    <button class="ghost" @click="confirmCancel = false">돌아가기</button>
                    <button class="dark" :disabled="busy" @click="m.my_status === 'host' ? cancelMeetup() : leave()">취소하기</button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.bg { height: 100%; display: flex; flex-direction: column; position: relative; }
.head { display: flex; align-items: center; justify-content: space-between; padding: 14px 12px 4px 8px; }
.head-title { font-size: 16px; font-weight: 700; }
.icon { width: 44px; height: 44px; border: none; background: none; display: flex; align-items: center; justify-content: center; }
.body { flex: 1; min-height: 0; padding: 4px 20px 120px; display: flex; flex-direction: column; gap: 14px; }

.hero { background: var(--c-primary); border-radius: 24px; padding: 18px; color: #fff; display: flex; flex-direction: column; gap: 12px; position: relative; overflow: hidden; }
.deco { position: absolute; right: -24px; top: -24px; width: 120px; height: 120px; border-radius: 60px; background: rgba(255,255,255,.10); }
.when-chip { align-self: flex-start; display: inline-flex; align-items: center; gap: 5px; background: rgba(255,255,255,.18); border-radius: 10px; padding: 4px 10px; font-size: 12px; font-weight: 700; }
.host-line { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #E6E1FF; }
.host-line b { color: #fff; }

.info { background: var(--c-surface); border-radius: 20px; padding: 6px 16px; display: flex; flex-direction: column; }
.row { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--c-primary-soft); font-size: 14px; }
.row.top { align-items: flex-start; }
.row.last { border-bottom: none; }
.k { width: 44px; font-size: 13px; color: var(--c-muted); flex-shrink: 0; }
.v { flex: 1; display: flex; flex-direction: column; gap: 3px; }
.v small { font-size: 12px; color: var(--c-muted); }
.v small.ok { color: var(--c-ok); }

.sec { display: flex; flex-direction: column; gap: 10px; }
.sec h2 { margin: 0; font-size: 16px; font-weight: 700; }
.slots { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 6px 6px; }
.slot { display: flex; flex-direction: column; align-items: center; gap: 4px; min-width: 0; }
.face { width: 48px; height: 48px; border-radius: 24px; background: var(--c-surface); display: flex; align-items: center; justify-content: center; border: 2px solid #fff; }
.face.me { border-color: var(--c-primary); }
.empty { width: 48px; height: 48px; border-radius: 24px; border: 2px dashed #CFC6FF; }
.nm { font-size: 11px; color: var(--c-ink-2); white-space: nowrap; max-width: 100%; overflow: hidden; text-overflow: ellipsis; }

.desc { background: var(--c-surface); border-radius: 20px; padding: 14px 16px; font-size: 14px; line-height: 1.7; color: var(--c-ink-2); white-space: pre-line; }
.safety { display: flex; gap: 10px; background: #FFF6E5; border-radius: 16px; padding: 12px 14px; font-size: 12px; color: #6B4500; line-height: 1.6; }

.bottom { position: absolute; left: 0; right: 0; bottom: 0; padding: 12px 20px 26px; background: var(--c-surface); border-top: 1px solid var(--c-divider); display: flex; gap: 10px; }
.grow { flex: 1; }
.ghost { height: 56px; padding: 0 16px; border: 1.5px solid var(--c-line); border-radius: 18px; background: var(--c-surface); color: var(--c-ink-2); font-size: 14px; font-weight: 700; }

.dim { position: absolute; inset: 0; background: rgba(31,27,58,.45); z-index: 30; }
.confirm { position: absolute; left: 24px; right: 24px; top: 240px; background: var(--c-surface); border-radius: 24px; padding: 22px 20px 18px; display: flex; flex-direction: column; gap: 12px; box-shadow: 0 12px 32px rgba(31,27,58,.25); }
.confirm h2 { margin: 0; font-size: 18px; text-align: center; }
.confirm p { margin: 0; font-size: 13px; color: var(--c-ink-2); text-align: center; line-height: 1.6; }
.btns { display: flex; gap: 8px; }
.btns button { flex: 1; height: 50px; border-radius: 14px; font-size: 15px; font-weight: 700; }
.btns .ghost { height: 50px; }
.dark { border: none; background: var(--c-ink); color: #fff; }
</style>
