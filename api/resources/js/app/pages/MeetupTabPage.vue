<script setup>
/**
 * 번개 탭 (디자인 없음 → 기존 카드·탭 요소로 구성)
 * - 내 번개: 참여·호스트 중인 번개 (채팅방 열림)
 * - 내 주변 번개: 광장 위치 기준 5km, 시작 시간 순
 * 번개는 광장에 연결되므로 '만들기'는 광장 안에서 한다 (안내 문구)
 */
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import MeetupCard from '../components/MeetupCard.vue';
import TabBar from '../components/TabBar.vue';
import { ApiError, get } from '../lib/api';
import { getLocation } from '../lib/location';
import { showToast } from '../lib/toast';

const router = useRouter();
const mine = ref([]);
const nearby = ref([]);
const loading = ref(true);

async function load() {
    try {
        const loc = await getLocation();
        const [a, b] = await Promise.all([get('/meetups/mine'), get(`/meetups?lat=${loc.lat}&lng=${loc.lng}`)]);
        mine.value = a.data;
        const mineIds = new Set(a.data.map((m) => m.id));
        nearby.value = b.data.filter((m) => !mineIds.has(m.id)); // 이미 참여한 건 위에서 보여줌
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '번개를 불러오지 못했어요');
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="tab">
        <div class="head">
            <h1 class="title" style="font-size: 26px">번개</h1>
        </div>

        <div class="body scroll">
            <section v-if="mine.length" class="sec">
                <h2>내 번개</h2>
                <button v-for="m in mine" :key="m.id" class="plain" @click="router.push({ name: 'meetup-room', params: { id: m.id } })">
                    <MeetupCard :m="m" highlight>
                        <span class="sub">{{ m.my_status === 'host' ? '내가 연 번개' : '참여 중' }} · {{ m.plaza.name }}</span>
                    </MeetupCard>
                </button>
            </section>

            <section class="sec">
                <h2>내 주변 번개</h2>
                <button v-for="m in nearby" :key="m.id" class="plain" @click="router.push({ name: 'meetup', params: { id: m.id } })">
                    <MeetupCard :m="m" />
                </button>
                <p v-if="!loading && !nearby.length" class="empty">지금 근처에 열린 번개가 없어요.</p>
            </section>

            <div class="tip">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#5B4BD6" stroke-width="2.2" stroke-linecap="round" aria-hidden="true" style="flex-shrink: 0; margin-top: 1px"><circle cx="12" cy="12" r="9" /><path d="M12 11v5M12 8h.01" /></svg>
                <span>번개는 광장에서 열 수 있어요. 광장에 들어가 위쪽 번개 버튼을 눌러보세요.</span>
            </div>
            <button class="btn-primary" @click="router.push({ name: 'main' })">광장 둘러보기</button>
        </div>

        <TabBar active="bungae" />
    </div>
</template>

<style scoped>
.tab { height: 100%; display: flex; flex-direction: column; position: relative; }
.head { padding: 18px 20px 6px; }
.body { flex: 1; min-height: 0; padding: 8px 20px 100px; display: flex; flex-direction: column; gap: 18px; }
.sec { display: flex; flex-direction: column; gap: 8px; }
h2 { margin: 0; font-size: 13px; font-weight: 700; color: var(--c-muted); }
.plain { border: none; background: none; padding: 0; text-align: left; }
.sub { font-size: 13px; color: var(--c-muted); }
.empty { margin: 0; padding: 24px 20px; background: var(--c-surface); border-radius: 18px; text-align: center; font-size: 14px; color: var(--c-muted); }
.tip { display: flex; gap: 8px; background: var(--c-primary-soft); border-radius: 14px; padding: 10px 12px; font-size: 12px; color: var(--c-primary-deep); line-height: 1.5; }
</style>
