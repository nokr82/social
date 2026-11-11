<script setup>
/**
 * '+' 버튼 시트: 사진 보내기 / (DM에서만) 번개 공유
 * - 사진: 파일 선택 → 부모에게 'image'(File) 전달
 * - 번개: 내가 참여 중인 '시작 전' 번개 목록 → 'meetup'(id) 전달
 */
import { ref } from 'vue';
import { get } from '../lib/api';
import { meetupWhen } from '../lib/time';
import { showToast } from '../lib/toast';

const props = defineProps({ allowMeetup: { type: Boolean, default: false } });
const emit = defineEmits(['close', 'image', 'meetup']);

const fileEl = ref(null);
const step = ref('menu');      // menu | meetups
const meetups = ref(null);

function onFile(e) {
    const file = e.target.files?.[0];
    e.target.value = ''; // 같은 사진을 다시 골라도 change가 일어나게
    if (file) emit('image', file);
}

async function openMeetups() {
    step.value = 'meetups';
    try {
        meetups.value = (await get('/meetups/mine')).data.filter((m) => m.is_upcoming);
    } catch {
        showToast('번개 목록을 불러오지 못했어요');
        meetups.value = [];
    }
}
</script>

<template>
    <div class="dim" @click.self="emit('close')">
        <div class="sheet" role="dialog" aria-label="첨부">
            <div class="grip" />
            <template v-if="step === 'menu'">
                <button class="opt" @click="fileEl.click()">
                    <span class="ic" aria-hidden="true">📷</span>
                    <span class="col"><b>사진</b><small>앨범에서 골라 보내요 (위치 정보는 지워져요)</small></span>
                </button>
                <button v-if="props.allowMeetup" class="opt" @click="openMeetups">
                    <span class="ic" aria-hidden="true">⚡</span>
                    <span class="col"><b>번개 공유</b><small>내가 참여 중인 번개를 초대장으로 보내요</small></span>
                </button>
                <input ref="fileEl" type="file" accept="image/*" hidden @change="onFile">
            </template>

            <template v-else>
                <b class="sheet-title">공유할 번개</b>
                <div class="list scroll">
                    <p v-if="meetups && !meetups.length" class="empty">참여 중인 시작 전 번개가 없어요</p>
                    <button v-for="m in meetups ?? []" :key="m.id" class="opt" @click="emit('meetup', m.id)">
                        <span class="ic" aria-hidden="true">⚡</span>
                        <span class="col"><b>{{ m.title }}</b><small>{{ meetupWhen(m.starts_at) }} · {{ m.area_label }} · {{ m.joined_count }}/{{ m.capacity }}명</small></span>
                    </button>
                </div>
            </template>
        </div>
    </div>
</template>

<style scoped>
.dim { position: absolute; inset: 0; z-index: 30; background: rgba(31,27,58,.35); display: flex; align-items: flex-end; }
.sheet { width: 100%; background: var(--c-surface); border-radius: 24px 24px 0 0; padding: 10px 16px 28px; display: flex; flex-direction: column; gap: 6px; max-height: 70%; }
.grip { width: 40px; height: 4px; border-radius: 2px; background: var(--c-line); align-self: center; margin-bottom: 8px; }
.sheet-title { font-size: 16px; padding: 0 4px 6px; }
.list { display: flex; flex-direction: column; gap: 6px; min-height: 0; }
.opt { display: flex; align-items: center; gap: 12px; text-align: left; border: none; background: var(--c-bg); border-radius: 16px; padding: 12px 14px; min-height: 56px; }
.ic { font-size: 22px; width: 32px; text-align: center; }
.col { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.col b { font-size: 15px; color: var(--c-ink); }
.col small { font-size: 12px; color: var(--c-muted); }
.empty { text-align: center; font-size: 14px; color: var(--c-muted); margin: 20px 0; }
</style>
