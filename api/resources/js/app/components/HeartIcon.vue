<script setup>
/** 하트 아이콘 (디자인 공통). filled=false면 테두리만 */
defineProps({ size: { type: Number, default: 16 }, filled: { type: Boolean, default: true } });
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 24 24" :fill="filled ? '#E8476A' : 'none'" stroke="#E8476A" :stroke-width="filled ? 0 : 2" aria-hidden="true">
        <path d="M12 21s-7.5-4.6-9.5-9.3C1.1 8.3 3.4 5 6.8 5c2 0 3.6 1.1 5.2 3 1.6-1.9 3.2-3 5.2-3 3.4 0 5.7 3.3 4.3 6.7C19.5 16.4 12 21 12 21z" />
    </svg>
</template>
