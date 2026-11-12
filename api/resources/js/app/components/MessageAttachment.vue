<script setup>
/**
 * 채팅 첨부 표시 (DM·번개방 공용)
 * - image : 원래 비율대로 미리 자리를 잡아(덜컹임 방지) 보여주고, 누르면 전체 화면
 * - meetup: 번개 카드 (제목·시간·장소·인원). 끝났거나 취소·삭제된 번개는 흐리게
 */
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { meetupWhen } from '../lib/time';

const props = defineProps({
    message: { type: Object, required: true },
    mine: { type: Boolean, default: false },
});
const router = useRouter();
const viewing = ref(false);

const MAX_W = 220;
const imgBox = computed(() => {
    const { width: w, height: h } = props.message.image ?? {};
    if (!w || !h) return { width: `${MAX_W}px`, height: `${MAX_W}px` };
    const scale = Math.min(1, MAX_W / w, 280 / h); // 세로 사진도 너무 길지 않게
    return { width: `${Math.round(w * scale)}px`, height: `${Math.round(h * scale)}px` };
});

const mt = computed(() => props.message.meetup);
const mtState = computed(() => {
    if (!mt.value) return '사라진 번개예요';
    if (mt.value.status !== 'open') return '취소된 번개예요';
    if (!mt.value.is_upcoming) return '이미 시작한 번개예요';
    return null;
});
</script>

<template>
    <!-- 사진 -->
    <span v-if="message.type === 'image' && !message.image?.url" class="gone">보관 기간이 지난 사진이에요</span>
    <template v-else-if="message.type === 'image'">
        <button class="img" :style="imgBox" aria-label="사진 크게 보기" @click="viewing = true">
            <img v-if="message.image?.url" :src="message.image.url" alt="보낸 사진" loading="lazy">
        </button>
        <Teleport to="body">
            <div v-if="viewing" class="viewer" role="dialog" aria-label="사진 보기" @click="viewing = false">
                <img :src="message.image.url" alt="보낸 사진">
                <button class="close" aria-label="닫기">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
                </button>
            </div>
        </Teleport>
    </template>

    <!-- 번개 카드 -->
    <button v-else-if="message.type === 'meetup'" class="mt" :class="{ mine, dead: mtState }" :disabled="!mt"
            @click="mt && router.push({ name: 'meetup', params: { id: mt.id } })">
        <span class="mt-head">
            <span class="bz" aria-hidden="true">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L4 14h7l-1 8 9-12h-7z" /></svg>
            </span>
            번개 초대
        </span>
        <b>{{ mt?.title ?? message.body }}</b>
        <template v-if="mt">
            <small>{{ meetupWhen(mt.starts_at) }} · {{ mt.area_label }}</small>
            <small>{{ mt.joined_count }}/{{ mt.capacity }}명 참여 · {{ mt.plaza_name }}</small>
        </template>
        <span v-if="mtState" class="state">{{ mtState }}</span>
        <span v-else class="go">자세히 보기</span>
    </button>
</template>

<style scoped>
.gone { font-size: 13px; color: var(--c-muted); background: var(--c-surface); border: 1px dashed var(--c-line); border-radius: 14px; padding: 10px 14px; }
.img { padding: 0; border: none; border-radius: 16px; overflow: hidden; background: var(--c-primary-soft); display: block; }
.img img { width: 100%; height: 100%; object-fit: cover; display: block; }
.viewer { position: fixed; inset: 0; z-index: 100; background: rgba(15,12,30,.92); display: flex; align-items: center; justify-content: center; }
.viewer img { max-width: 100%; max-height: 100%; object-fit: contain; }
.close { position: absolute; top: 16px; right: 16px; width: 44px; height: 44px; border: none; border-radius: 22px; background: rgba(255,255,255,.15); display: flex; align-items: center; justify-content: center; }

.mt { width: 230px; text-align: left; border: 1.5px solid var(--c-primary-soft); background: var(--c-surface); border-radius: 18px; padding: 12px 14px; display: flex; flex-direction: column; gap: 4px; }
.mt.mine { border-color: var(--c-primary); }
.mt.dead { opacity: .6; }
.mt-head { display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 700; color: var(--c-primary-deep); }
.bz { width: 22px; height: 22px; border-radius: 11px; background: var(--c-primary); display: flex; align-items: center; justify-content: center; }
.mt b { font-size: 15px; color: var(--c-ink); margin-top: 2px; }
.mt small { font-size: 12px; color: var(--c-muted); }
.go { margin-top: 6px; font-size: 13px; font-weight: 700; color: var(--c-primary-ink); }
.state { margin-top: 6px; font-size: 12px; font-weight: 700; color: var(--c-muted); }
</style>
