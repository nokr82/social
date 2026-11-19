<script setup>
/**
 * 번개 친구 초대 시트: 친구를 여러 명 골라 한 번에 초대 → 각자의 1:1 대화방에 초대 카드
 * - 이미 참여 중·이미 초대함·조건이 안 맞아요는 고를 수 없게 (이유는 나이·차단을 구분하지 않음)
 * - 정원이 찼으면 맨 위 안내 + 보내기 막음, 남은 초대 수(20명까지) 표시
 * - 초대는 입장권이 아님: 친구가 참여할 때 서버가 연령·정원을 다시 확인
 */
import { computed, onMounted, ref } from 'vue';
import AvatarFace from './AvatarFace.vue';
import { ApiError, get, post } from '../lib/api';
import { showToast } from '../lib/toast';

const props = defineProps({ meetupId: { type: [String, Number], required: true } });
const emit = defineEmits(['close']);

const data = ref(null);           // {full, remaining, friends[{user, status}]}
const picked = ref(new Set());
const busy = ref(false);

const STATUS_TEXT = { joined: '참여 중', invited: '초대함', ineligible: '조건이 안 맞아요' };

const available = computed(() => (data.value?.friends ?? []).filter((f) => f.status === 'available'));
const overLimit = computed(() => data.value && picked.value.size > data.value.remaining);
const canSend = computed(() => data.value && !data.value.full && picked.value.size > 0 && !overLimit.value && !busy.value);
const sendText = computed(() => {
    if (data.value?.full) return '자리가 없어요';
    if (overLimit.value) return `${data.value.remaining}명까지 더 초대할 수 있어요`;
    return picked.value.size ? `${picked.value.size}명 초대 보내기` : '초대할 친구를 골라 주세요';
});

async function load() {
    try {
        data.value = (await get(`/meetups/${props.meetupId}/invites`)).data;
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '친구 목록을 불러오지 못했어요');
        emit('close');
    }
}

function toggle(f) {
    if (f.status !== 'available' || data.value.full) return;
    const next = new Set(picked.value); // Set은 새로 만들어야 화면이 다시 그려짐
    next.has(f.user.id) ? next.delete(f.user.id) : next.add(f.user.id);
    picked.value = next;
}

async function send() {
    if (!canSend.value) return;
    busy.value = true;
    try {
        const res = (await post(`/meetups/${props.meetupId}/invites`, { user_ids: [...picked.value] })).data;
        showToast(res.sent ? `${res.sent}명에게 초대를 보냈어요` : '보낼 수 있는 친구가 없었어요');
        emit('close');
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '잠시 후 다시 시도해 주세요');
        await load(); // 정원이 찼거나 상태가 바뀐 경우 최신으로
        picked.value = new Set();
    } finally {
        busy.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="dim" @click.self="emit('close')">
        <div class="sheet" role="dialog" aria-label="친구 초대">
            <div class="grip" />
            <b class="sheet-title">친구 초대</b>
            <p class="hint">고른 친구의 1:1 대화방에 번개 초대장을 보내요. 참여 조건은 똑같이 적용돼요.</p>
            <p v-if="data?.full" class="warn">정원이 가득 차서 지금은 초대할 수 없어요</p>

            <div class="list scroll">
                <p v-if="data && !data.friends.length" class="empty">아직 친구가 없어요. 광장에서 친구를 만들어 보세요</p>
                <p v-else-if="data && !available.length && !data.full" class="empty-sm">초대할 수 있는 친구가 없어요</p>
                <button
                    v-for="f in data?.friends ?? []" :key="f.user.id" class="row"
                    :class="{ off: f.status !== 'available' || data.full, on: picked.has(f.user.id) }"
                    :aria-pressed="picked.has(f.user.id)" :disabled="f.status !== 'available' || data.full"
                    @click="toggle(f)"
                >
                    <AvatarFace :parts="f.user.avatar" :size="40" variant="mini" />
                    <span class="name">{{ f.user.nickname }}</span>
                    <span v-if="f.status !== 'available'" class="state">{{ STATUS_TEXT[f.status] }}</span>
                    <span v-else class="check" aria-hidden="true">
                        <svg v-if="picked.has(f.user.id)" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7" /></svg>
                    </span>
                </button>
            </div>

            <small v-if="data && !data.full" class="remain">이 번개에서 {{ data.remaining }}명 더 초대할 수 있어요</small>
            <button class="btn-primary" :disabled="!canSend" @click="send">{{ busy ? '보내는 중…' : sendText }}</button>
        </div>
    </div>
</template>

<style scoped>
.dim { position: absolute; inset: 0; z-index: 30; background: rgba(31, 27, 58, .35); display: flex; align-items: flex-end; }
.sheet { width: 100%; background: var(--c-surface); border-radius: 24px 24px 0 0; padding: 10px 16px 28px; display: flex; flex-direction: column; gap: 8px; max-height: 80%; }
.grip { width: 40px; height: 4px; border-radius: 2px; background: var(--c-line); align-self: center; margin-bottom: 6px; }
.sheet-title { font-size: 17px; padding: 0 4px; }
.hint { margin: 0; padding: 0 4px; font-size: 13px; color: var(--c-muted); line-height: 1.5; }
.warn { margin: 0; padding: 10px 12px; border-radius: 12px; background: #FFF1F0; color: var(--c-danger); font-size: 13px; font-weight: 700; }
.list { flex: 1; min-height: 120px; overflow-y: auto; display: flex; flex-direction: column; }
.empty { margin: 24px 0; text-align: center; color: var(--c-muted); font-size: 14px; }
.empty-sm { margin: 4px 4px 8px; color: var(--c-muted); font-size: 13px; }
.row { min-height: 56px; display: flex; align-items: center; gap: 12px; padding: 6px 4px; border: none; background: none; text-align: left; border-radius: 12px; }
.row.on { background: var(--c-primary-soft); }
.row.off { opacity: .55; }
.name { flex: 1; min-width: 0; font-size: 15px; font-weight: 700; color: var(--c-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.state { font-size: 12px; color: var(--c-muted); }
.check { width: 24px; height: 24px; border-radius: 12px; border: 2px solid var(--c-line); display: flex; align-items: center; justify-content: center; }
.row.on .check { background: var(--c-primary); border-color: var(--c-primary); }
.remain { padding: 0 4px; font-size: 12px; color: var(--c-muted); }
</style>
