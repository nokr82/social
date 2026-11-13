<script setup>
/**
 * 내 위치 공유 설정 (친구 지도의 '내 위치 공유' 버튼)
 * - 친구별로 켜기: 1시간 / 오늘(자정까지) / 끌 때까지, 끄기
 * - 맨 위에 무엇이 공유되는지 안내 (정확한 위치, 앱을 연 동안만, 볼 때마다 알림)
 */
import { computed, onMounted, ref } from 'vue';
import AvatarFace from './AvatarFace.vue';
import { ApiError, get } from '../lib/api';
import { locShare, remainText, shareWith, stopAllSharing, stopSharingWith } from '../lib/locationShare';
import { showToast } from '../lib/toast';

const emit = defineEmits(['close']);

const DURATIONS = [
    { code: '1h', label: '1시간' },
    { code: 'today', label: '오늘' },
    { code: 'always', label: '계속' },
];

const friends = ref([]);
const open = ref(null);   // 기간을 고르는 중인 친구 id
const busy = ref(false);

const sharingById = computed(() => Object.fromEntries(locShare.shares.map((s) => [s.user.id, s])));

onMounted(async () => {
    try {
        friends.value = (await get('/friends')).data;
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '친구 목록을 불러오지 못했어요');
    }
});

async function run(fn, done) {
    busy.value = true;
    try {
        await fn();
        open.value = null;
        if (done) showToast(done);
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '잠시 후 다시 시도해 주세요');
    } finally {
        busy.value = false;
    }
}

const choose = (f, d) => run(() => shareWith(f.id, d.code), `${f.nickname}님에게 위치를 공유해요`);
const stop = (f) => run(() => stopSharingWith(f.id), `${f.nickname}님에게 공유를 껐어요`);
const stopAll = () => run(stopAllSharing, '위치 공유를 모두 껐어요');
</script>

<template>
    <div class="dim" @click.self="emit('close')">
        <div class="sheet" role="dialog" aria-label="내 위치 공유">
            <div class="grip" />
            <h2 class="title" style="font-size: 20px">내 위치 공유</h2>
            <p class="notice">
                켜면 그 친구가 지도에서 <b>내 정확한 위치</b>를 볼 수 있어요.
                앱을 열어 둔 동안만 갱신되고, 친구가 내 위치를 볼 때마다 알려드려요.
            </p>
            <p v-if="locShare.denied" class="warn">위치 권한이 꺼져 있어요. 브라우저 설정에서 이 사이트의 위치 권한을 허용해 주세요.</p>

            <div class="list scroll">
                <p v-if="!friends.length" class="empty">아직 친구가 없어요</p>
                <div v-for="f in friends" :key="f.id" class="item">
                    <button class="row" :aria-expanded="open === f.id" @click="open = open === f.id ? null : f.id">
                        <AvatarFace :parts="f.avatar" :size="40" variant="mini" />
                        <span class="col">
                            <b>{{ f.nickname }}</b>
                            <small :class="{ on: sharingById[f.id] }">
                                {{ sharingById[f.id] ? `공유 중 · ${remainText(sharingById[f.id].until)}` : '공유 안 함' }}
                            </small>
                        </span>
                        <span class="chev" aria-hidden="true">{{ open === f.id ? '▴' : '▾' }}</span>
                    </button>
                    <div v-if="open === f.id" class="chips">
                        <button v-for="d in DURATIONS" :key="d.code" class="chip" :disabled="busy" @click="choose(f, d)">{{ d.label }}</button>
                        <button v-if="sharingById[f.id]" class="chip off" :disabled="busy" @click="stop(f)">끄기</button>
                    </div>
                </div>
            </div>

            <button v-if="locShare.shares.length" class="stop-all" :disabled="busy" @click="stopAll">모두 끄기</button>
            <button class="close" @click="emit('close')">닫기</button>
        </div>
    </div>
</template>

<style scoped>
.dim { position: absolute; inset: 0; z-index: 30; background: rgba(31, 27, 58, .4); display: flex; align-items: flex-end; }
.sheet { width: 100%; max-height: 85%; background: var(--c-surface); border-radius: 24px 24px 0 0; padding: 10px 20px 20px; display: flex; flex-direction: column; gap: 10px; }
.grip { width: 40px; height: 4px; border-radius: 2px; background: var(--c-line); margin: 0 auto 6px; }
.notice { margin: 0; font-size: 13px; line-height: 1.5; color: var(--c-ink-2); background: var(--c-primary-soft); border-radius: 14px; padding: 10px 12px; }
.warn { margin: 0; font-size: 13px; color: var(--c-danger); }
.list { flex: 1; min-height: 0; display: flex; flex-direction: column; gap: 4px; }
.empty { text-align: center; color: var(--c-muted); font-size: 14px; }
.row { width: 100%; display: flex; align-items: center; gap: 12px; border: none; background: none; padding: 8px 0; text-align: left; }
.col { flex: 1; display: flex; flex-direction: column; gap: 2px; }
.col b { font-size: 15px; color: var(--c-ink); }
.col small { font-size: 12px; color: var(--c-muted); }
.col small.on { color: var(--c-live-ink); font-weight: 700; }
.chev { color: var(--c-muted); }
.chips { display: flex; gap: 8px; padding: 0 0 10px 52px; }
.chip { height: 34px; padding: 0 14px; border: 1.5px solid var(--c-primary-line); border-radius: 17px; background: var(--c-surface); color: var(--c-primary-deep); font-size: 13px; font-weight: 700; }
.chip.off { border-color: #F3C9C4; color: var(--c-danger); }
.stop-all { height: 48px; border: none; border-radius: 16px; background: #FDECEA; color: var(--c-danger); font-size: 15px; font-weight: 700; }
.close { height: 44px; border: none; background: none; font-size: 15px; font-weight: 700; color: var(--c-ink-2); }
</style>
