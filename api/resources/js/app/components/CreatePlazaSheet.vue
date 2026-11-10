<script setup>
/**
 * 광장 만들기 시트. (디자인 시안에 전용 화면이 없어 온보딩·신고 화면의 입력 요소를 재사용)
 * 만들면 서버가 만든 사람을 자동 입장시키므로 바로 광장 화면으로 이동한다.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import { ApiError, get, post } from '../lib/api';
import { getLocation } from '../lib/location';

const emit = defineEmits(['close', 'created']);

const categories = ref([]);
const form = reactive({ name: '', category: '', description: '' });
const busy = ref(false);
const error = ref('');

const valid = computed(() => form.name.trim().length >= 2 && form.category);

onMounted(async () => {
    categories.value = (await get('/meta/categories')).data;
    form.category = categories.value[0]?.code ?? '';
});

async function submit() {
    if (!valid.value || busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        const loc = await getLocation();
        const res = await post('/plazas', {
            name: form.name.trim(),
            category: form.category,
            description: form.description.trim() || null,
            lat: loc.lat,
            lng: loc.lng,
        });
        emit('created', res.data);
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : '네트워크를 확인해 주세요';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="dim" @click.self="emit('close')">
        <div class="sheet" role="dialog" aria-label="광장 만들기">
            <div class="grip" />
            <h2 class="title" style="font-size: 22px">광장 만들기</h2>

            <label class="field">
                <span class="label">광장 이름</span>
                <input v-model="form.name" class="input" maxlength="40" placeholder="예) 역삼 퇴근길 수다방">
            </label>

            <div class="field">
                <span class="label">카테고리</span>
                <div class="row">
                    <button
                        v-for="c in categories" :key="c.code"
                        class="chip" :class="{ on: form.category === c.code }" :aria-pressed="form.category === c.code"
                        @click="form.category = c.code"
                    >{{ c.label }}</button>
                </div>
            </div>

            <label class="field">
                <span class="label">한 줄 소개 <small>(선택)</small></span>
                <input v-model="form.description" class="input" maxlength="200" placeholder="어떤 이야기를 나누는 곳인가요?">
            </label>

            <p class="hint">광장 위치는 지금 있는 곳 근처(약 150m 범위)로 정해지고, 정확한 위치는 공개되지 않아요.</p>
            <p v-if="error" class="err" role="alert">{{ error }}</p>

            <button class="btn-primary" :disabled="!valid || busy" @click="submit">만들고 입장하기</button>
        </div>
    </div>
</template>

<style scoped>
.dim { position: absolute; inset: 0; background: rgba(31, 27, 58, .45); display: flex; align-items: flex-end; z-index: 30; }
.sheet { width: 100%; background: var(--c-surface); border-radius: 28px 28px 0 0; padding: 10px 20px 30px; display: flex; flex-direction: column; gap: 16px; }
.grip { align-self: center; width: 40px; height: 5px; border-radius: 3px; background: var(--c-line); }
.field { display: flex; flex-direction: column; gap: 8px; }
.label { font-size: 13px; font-weight: 700; color: var(--c-ink-2); }
.label small { font-weight: 500; color: var(--c-muted); }
.row { display: flex; gap: 8px; flex-wrap: wrap; }
.chip { height: 40px; padding: 0 16px; border: 1.5px solid var(--c-line); border-radius: 20px; background: var(--c-surface); color: var(--c-ink-2); font-size: 14px; font-weight: 500; }
.chip.on { border-color: var(--c-primary); background: var(--c-primary); color: #fff; font-weight: 700; }
.input { height: 50px; border: 1.5px solid var(--c-line); border-radius: 14px; padding: 0 14px; font-size: 15px; color: var(--c-ink); background: var(--c-surface); outline: none; }
.input:focus { border-color: var(--c-primary); }
.hint { margin: 0; font-size: 12px; color: var(--c-muted); line-height: 1.5; }
.err { margin: 0; font-size: 13px; color: var(--c-danger); }
</style>
