<script setup>
/**
 * 사진 크게 보기 (프로필 사진용)
 * - 전체 화면에 원본 비율로, 좌우로 넘겨 같은 묶음(공개 / 친구 공개)의 다른 사진을 본다
 * - 넘기기는 라이브러리 없이 CSS scroll-snap: 브라우저 기본 스크롤이라 관성·손가락 따라오기가 자연스럽다
 * - 열고 닫기·몇 번째인지는 부모가 주소(?photo=)로 관리한다 → 휴대폰 뒤로가기로 사진만 닫힘
 * - 하트는 부모의 toggleLike를 그대로 쓴다 (같은 사진 객체를 바꾸므로 격자 숫자도 함께 바뀜)
 */
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import HeartIcon from './HeartIcon.vue';

const props = defineProps({
    photos: { type: Array, required: true },   // [{id, url, liked, like_count}]
    start: { type: Number, default: 0 },        // 처음 보여줄 사진 순번
    canLike: { type: Boolean, default: false }, // 내 프로필이면 하트 숨김
});
const emit = defineEmits(['close', 'change', 'like']);

const track = ref(null);
const closeBtn = ref(null);
const index = ref(props.start);
let scrollTimer = null;

/** 순번의 사진으로 이동 (smooth=false: 처음 열 때는 애니메이션 없이 바로) */
function goTo(i, smooth = true) {
    const el = track.value;
    if (!el) return;
    const n = Math.max(0, Math.min(props.photos.length - 1, i));
    // 'instant' 옵션은 구형 사파리가 몰라서, 바로 이동은 scrollLeft를 직접 넣는다
    if (smooth) el.scrollTo({ left: n * el.clientWidth, behavior: 'smooth' });
    else el.scrollLeft = n * el.clientWidth;
}

/** 스크롤이 멈춘 뒤 몇 번째 사진인지 계산 (스크롤 중 매 프레임마다 주소를 바꾸지 않도록 잠깐 기다림) */
function onScroll() {
    clearTimeout(scrollTimer);
    scrollTimer = setTimeout(() => {
        const el = track.value;
        if (!el || !el.clientWidth) return;
        const i = Math.round(el.scrollLeft / el.clientWidth);
        if (i !== index.value) {
            index.value = i;
            emit('change', i);
        }
    }, 120);
}

function onKey(e) {
    if (e.key === 'Escape') emit('close');
    else if (e.key === 'ArrowRight') goTo(index.value + 1);
    else if (e.key === 'ArrowLeft') goTo(index.value - 1);
}

// 주소가 바깥에서 바뀐 경우(앞으로가기 등)에만 따라감. 내가 넘겨서 바뀐 값이면 이미 같은 위치
watch(() => props.start, (i) => {
    if (i !== index.value) {
        index.value = i;
        goTo(i, false);
    }
});

onMounted(async () => {
    window.addEventListener('keydown', onKey);
    await nextTick();
    goTo(index.value, false);
    closeBtn.value?.focus(); // 키보드·스크린리더 사용자가 바로 닫을 수 있게
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    clearTimeout(scrollTimer);
});
</script>

<template>
    <Teleport to="body">
        <div class="viewer" role="dialog" aria-modal="true" aria-label="사진 크게 보기">
            <div class="top">
                <span class="count" aria-live="polite">{{ index + 1 }} / {{ photos.length }}</span>
                <button ref="closeBtn" class="close" aria-label="닫기" @click="emit('close')">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
                </button>
            </div>

            <!-- 사진 밖(검은 배경)을 누르면 닫힘. 사진 자체를 누를 때는 닫지 않음 (넘기다 실수로 닫히는 것 방지) -->
            <div ref="track" class="track" @scroll.passive="onScroll">
                <div v-for="(p, i) in photos" :key="p.id" class="slide" @click.self="emit('close')">
                    <img :src="p.url" :alt="`사진 ${i + 1}`" :loading="Math.abs(i - start) <= 1 ? 'eager' : 'lazy'" draggable="false">
                </div>
            </div>

            <!-- 좌우 버튼: 터치가 없는 PC용 (휴대폰은 손가락으로 넘김) -->
            <button v-if="index > 0" class="nav prev" aria-label="이전 사진" @click="goTo(index - 1)">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
            </button>
            <button v-if="index < photos.length - 1" class="nav next" aria-label="다음 사진" @click="goTo(index + 1)">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6" /></svg>
            </button>

            <button
                v-if="canLike && photos[index]" class="like"
                :aria-pressed="photos[index].liked"
                :aria-label="`${photos[index].liked ? '하트 취소' : '하트 누르기'}, 현재 ${photos[index].like_count}개`"
                @click="emit('like', photos[index])"
            >
                <HeartIcon :size="20" :filled="photos[index].liked" />{{ photos[index].like_count }}
            </button>
        </div>
    </Teleport>
</template>

<style scoped>
.viewer { position: fixed; inset: 0; z-index: 100; background: rgba(15,12,30,.96); display: flex; flex-direction: column; }
.top { position: absolute; top: 0; left: 0; right: 0; z-index: 2; display: flex; align-items: center; justify-content: center; padding: 16px; pointer-events: none; }
.count { color: #fff; font-size: 14px; font-weight: 700; background: rgba(255,255,255,.15); padding: 4px 12px; border-radius: 12px; }
.close { position: absolute; top: 16px; right: 16px; width: 44px; height: 44px; border: none; border-radius: 22px; background: rgba(255,255,255,.15); display: flex; align-items: center; justify-content: center; pointer-events: auto; }

/* 가로 스크롤 + 한 장씩 멈춤. 스크롤바는 숨김 */
.track { flex: 1; display: flex; overflow-x: auto; overflow-y: hidden; scroll-snap-type: x mandatory; overscroll-behavior: contain; scrollbar-width: none; }
.track::-webkit-scrollbar { display: none; }
.slide { flex: 0 0 100%; scroll-snap-align: center; scroll-snap-stop: always; display: flex; align-items: center; justify-content: center; padding: 72px 0 96px; }
.slide img { max-width: 100%; max-height: 100%; object-fit: contain; user-select: none; -webkit-user-drag: none; }

.nav { position: absolute; top: 50%; transform: translateY(-50%); width: 44px; height: 44px; border: none; border-radius: 22px; background: rgba(255,255,255,.15); display: none; align-items: center; justify-content: center; }
.nav.prev { left: 12px; }
.nav.next { right: 12px; }
/* 마우스가 있는 환경에서만 좌우 버튼 표시 */
@media (hover: hover) and (pointer: fine) { .nav { display: flex; } }

.like { position: absolute; left: 50%; bottom: 28px; transform: translateX(-50%); height: 44px; padding: 0 16px; border: none; border-radius: 22px; background: rgba(255,255,255,.92); display: flex; align-items: center; gap: 6px; font-size: 15px; font-weight: 700; color: var(--c-ink); }
</style>
