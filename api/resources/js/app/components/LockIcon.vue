<script setup>
defineProps({ size: { type: Number, default: 14 }, color: { type: String, default: '#5B4BD6' } });
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" :stroke="color" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
        <rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" />
    </svg>
</template>
