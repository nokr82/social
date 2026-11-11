<script setup>
/**
 * 디자인의 동그란 아바타 얼굴 (SVG).
 * parts: 서버 아바타 {color, face}. variant='mini'는 목록용(입 없음, 꽉 찬 원).
 */
import { computed } from 'vue';
import { colorHex, mouthPath } from '../lib/avatar';

const props = defineProps({
    parts: { type: Object, default: () => ({}) },
    size: { type: Number, default: 30 },
    variant: { type: String, default: 'full' }, // full | mini(꽉 찬 원, 40px 미만은 입 생략)
});

const fill = computed(() => colorHex(props.parts?.color));
const mouth = computed(() => mouthPath(props.parts?.face));
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 30 30" aria-hidden="true">
        <template v-if="variant === 'mini'">
            <circle cx="15" cy="15" r="14" :fill="fill" />
            <circle cx="11.5" cy="14" r="1.8" fill="#1F1B3A" />
            <circle cx="18.5" cy="14" r="1.8" fill="#1F1B3A" />
            <!-- 목록용 큰 아바타(40px 이상)는 입까지 (디자인: 채팅·친구 목록) -->
            <path v-if="size >= 40" :d="mouth" transform="translate(0 -1)" stroke="#1F1B3A" stroke-width="1.4" :fill="parts?.face === 'grin' ? '#1F1B3A' : 'none'" stroke-linecap="round" />
        </template>
        <template v-else>
            <circle cx="15" cy="16" r="12" :fill="fill" />
            <circle cx="11.5" cy="15" r="1.6" fill="#1F1B3A" />
            <circle cx="18.5" cy="15" r="1.6" fill="#1F1B3A" />
            <path :d="mouth" stroke="#1F1B3A" stroke-width="1.4" :fill="parts?.face === 'grin' ? '#1F1B3A' : 'none'" stroke-linecap="round" />
        </template>
    </svg>
</template>
