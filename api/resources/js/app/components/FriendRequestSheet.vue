<script setup>
/**
 * 친구 요청 보내기 시트: 한마디(선택) 입력.
 * 디자인의 친구 요청 카드에 '한마디'가 보이므로 보낼 때 받을 수 있게 한다.
 */
import { ref } from 'vue';
import AvatarFace from './AvatarFace.vue';
import { ApiError, post } from '../lib/api';

const props = defineProps({
    user: { type: Object, required: true },       // {id, nickname, avatar}
    plazaId: { type: [Number, String], default: null }, // 어디서 만났는지
});
const emit = defineEmits(['close', 'sent']);

const message = ref('');
const busy = ref(false);
const error = ref('');

async function send() {
    busy.value = true;
    error.value = '';
    try {
        await post('/friend-requests', {
            user_id: props.user.id,
            message: message.value.trim() || null,
            plaza_id: props.plazaId ? Number(props.plazaId) : null,
        });
        emit('sent');
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : '요청을 보내지 못했어요';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="dim" @click.self="emit('close')">
        <div class="sheet" role="dialog" :aria-label="`${user.nickname}님에게 친구 요청`">
            <div class="grip" />
            <div class="who">
                <AvatarFace :parts="user.avatar" :size="44" />
                <b>{{ user.nickname }}님에게 친구 요청</b>
            </div>
            <label class="field">
                <span class="label">한마디 <small>(선택)</small></span>
                <input v-model="message" class="input" maxlength="100" placeholder="예) 아까 야근 얘기 재밌었어요 ㅋㅋ">
            </label>
            <p class="hint">수락하면 서로의 비공개 사진·성별·나이가 공개돼요. 7일 동안 응답이 없으면 요청은 사라져요.</p>
            <p v-if="error" class="err" role="alert">{{ error }}</p>
            <button class="btn-primary" :disabled="busy" @click="send">요청 보내기</button>
        </div>
    </div>
</template>

<style scoped>
.dim { position: absolute; inset: 0; background: rgba(31,27,58,.45); display: flex; align-items: flex-end; z-index: 40; }
.sheet { width: 100%; background: var(--c-surface); border-radius: 28px 28px 0 0; padding: 10px 20px 30px; display: flex; flex-direction: column; gap: 14px; }
.grip { align-self: center; width: 40px; height: 5px; border-radius: 3px; background: var(--c-line); }
.who { display: flex; align-items: center; gap: 10px; font-size: 16px; }
.field { display: flex; flex-direction: column; gap: 8px; }
.label { font-size: 13px; font-weight: 700; color: var(--c-ink-2); }
.label small { font-weight: 500; color: var(--c-muted); }
.input { height: 50px; border: 1.5px solid var(--c-line); border-radius: 14px; padding: 0 14px; font-size: 15px; color: var(--c-ink); background: var(--c-surface); outline: none; }
.input:focus { border-color: var(--c-primary); }
.hint { margin: 0; font-size: 12px; color: var(--c-muted); line-height: 1.5; }
.err { margin: 0; font-size: 13px; color: var(--c-danger); }
</style>
