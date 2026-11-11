<script setup>
/** 번개 목록 카드 (번개 탭·채팅 탭 공용). 시간 박스 + 제목 + 광장·위치 + 인원 */
import { computed } from 'vue';
import { meetupWhen, until } from '../lib/time';

const props = defineProps({ m: { type: Object, required: true }, highlight: { type: Boolean, default: false } });
const when = computed(() => meetupWhen(props.m.starts_at).split(' '));
</script>

<template>
    <span class="card" :class="{ hl: highlight }">
        <span class="date"><small>{{ when[0] }}</small><b>{{ when.slice(2).join(' ') }}</b></span>
        <span class="col">
            <b class="t">{{ m.title }}</b>
            <span class="meta">{{ m.joined_count }}/{{ m.capacity }}명 · {{ until(m.starts_at) }}</span>
            <slot>
                <span class="sub">{{ m.plaza.name }} · {{ m.area_label }}<template v-if="m.distance_label"> · {{ m.distance_label }}</template></span>
            </slot>
        </span>
        <slot name="side" />
    </span>
</template>

<style scoped>
.card { width: 100%; background: var(--c-surface); border-radius: 18px; padding: 12px; display: flex; gap: 12px; align-items: center; text-align: left; box-shadow: 0 2px 10px rgba(108,92,231,.07); border: 2px solid transparent; }
.card.hl { border-color: var(--c-primary); }
.date { width: 52px; height: 52px; border-radius: 16px; background: var(--c-primary); color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; flex-shrink: 0; }
.date small { font-size: 11px; font-weight: 700; }
.date b { font-size: 13px; }
.col { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
.t { font-size: 15px; color: var(--c-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.meta { font-size: 12px; color: var(--c-primary-ink); font-weight: 700; }
.sub { font-size: 13px; color: var(--c-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
</style>
