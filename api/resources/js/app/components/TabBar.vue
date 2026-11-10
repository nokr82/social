<script setup>
/** 하단 탭바. 1차에서는 '광장'만 열려 있고 나머지는 준비 중 안내 */
import { comingSoon } from '../lib/toast';

const tabs = [
    { key: 'plaza', label: '광장', icon: 'M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z' },
    { key: 'bungae', label: '번개', icon: 'M13 2L4 14h7l-1 8 9-12h-7z' },
    { key: 'chat', label: '채팅', icon: 'M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z' },
];
</script>

<template>
    <nav class="tabbar" aria-label="하단 메뉴">
        <button
            v-for="t in tabs"
            :key="t.key"
            :aria-current="t.key === 'plaza' ? 'page' : undefined"
            :class="{ on: t.key === 'plaza' }"
            @click="t.key !== 'plaza' && comingSoon(t.label)"
        >
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="t.icon" /></svg>
            {{ t.label }}
        </button>
        <button @click="comingSoon('MY')">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" /></svg>
            MY
        </button>
    </nav>
</template>

<style scoped>
.tabbar {
    position: absolute; left: 0; right: 0; bottom: 0; height: 80px;
    background: var(--c-surface); border-top: 1px solid var(--c-divider);
    display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); padding-bottom: 12px;
}
.tabbar button {
    border: none; background: none; display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 4px; color: var(--c-muted); font-size: 11px; font-weight: 500;
}
.tabbar button.on { color: var(--c-primary-ink); font-weight: 700; }
</style>
