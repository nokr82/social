<script setup>
/**
 * MY > 차단 목록
 * - 내가 차단한 사람만 보여줌 (누가 나를 차단했는지는 절대 노출하지 않음)
 * - 해제는 바로 목록에서 빼지 않고 '해제됨 · 다시 차단' 상태로 남겨 실수를 되돌릴 수 있게
 * - 차단할 때 끊긴 친구 관계는 해제해도 복구되지 않음 → 안내 문구로 알림
 */
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import AvatarFace from '../components/AvatarFace.vue';
import { ApiError, del, get, post } from '../lib/api';
import { ago } from '../lib/time';
import { showToast } from '../lib/toast';

const router = useRouter();
const items = ref([]);
const loading = ref(true);
const busy = ref(new Set());

async function load() {
    try {
        items.value = (await get('/blocks/mine')).data.map((b) => ({ ...b, unblocked: false }));
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '불러오지 못했어요');
    } finally {
        loading.value = false;
    }
}

async function toggle(b) {
    const id = b.user.id;
    if (busy.value.has(id)) return;
    busy.value.add(id);
    try {
        if (b.unblocked) await post('/blocks', { user_id: id });
        else await del(`/blocks/${id}`);
        b.unblocked = !b.unblocked;
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '잠시 후 다시 시도해 주세요');
    } finally {
        busy.value.delete(id);
    }
}

onMounted(load);
</script>

<template>
    <div class="bl">
        <div class="head">
            <button class="icon" aria-label="뒤로가기" @click="router.back()">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
            </button>
            <h1 class="title" style="font-size: 22px">차단 목록</h1>
        </div>
        <p class="note">차단한 사람은 내 광장·채팅·번개에서 서로 보이지 않아요. 해제해도 끊긴 친구 관계는 복구되지 않아요.</p>

        <div class="list scroll">
            <p v-if="!loading && !items.length" class="empty">차단한 사람이 없어요</p>
            <div v-for="b in items" :key="b.user.id" class="row" :class="{ off: b.unblocked }">
                <span class="av"><AvatarFace :parts="b.user.avatar" :size="40" /></span>
                <span class="col">
                    <b>{{ b.user.nickname }}</b>
                    <small>{{ b.unblocked ? '차단 해제됨' : `${ago(b.blocked_at)} 차단` }}</small>
                </span>
                <button :class="b.unblocked ? 'reblock' : 'unblock'" :disabled="busy.has(b.user.id)" @click="toggle(b)">
                    {{ b.unblocked ? '다시 차단' : '차단 해제' }}
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.bl { height: 100%; display: flex; flex-direction: column; }
.head { display: flex; align-items: center; gap: 4px; padding: 14px 12px 6px 8px; }
.icon { width: 44px; height: 44px; border: none; background: none; display: flex; align-items: center; justify-content: center; }
.note { margin: 0 20px; font-size: 13px; line-height: 1.5; color: var(--c-muted); }
.list { flex: 1; min-height: 0; padding: 16px 20px 32px; display: flex; flex-direction: column; gap: 10px; }
.empty { margin: 40px 0 0; text-align: center; font-size: 14px; color: var(--c-muted); }
.row { display: flex; align-items: center; gap: 12px; background: var(--c-surface); border-radius: 18px; padding: 12px 14px; }
.row.off { opacity: .7; }
.av { width: 46px; height: 46px; border-radius: 23px; background: var(--c-primary-soft); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.col { flex: 1; display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.col b { font-size: 15px; }
.col small { font-size: 12px; color: var(--c-muted); }
.row button { height: 36px; padding: 0 12px; border-radius: 12px; font-size: 13px; font-weight: 700; flex-shrink: 0; }
.unblock { border: 1.5px solid var(--c-line); background: var(--c-surface); color: var(--c-ink-2); }
.reblock { border: none; background: var(--c-primary-soft); color: var(--c-primary-deep); }
</style>
