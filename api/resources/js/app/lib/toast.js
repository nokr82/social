/** 간단한 토스트 메시지 (에러·준비 중 안내). App.vue가 표시한다. */
import { reactive } from 'vue';

export const toast = reactive({ text: '', timer: null });

export function showToast(text, ms = 2200) {
    clearTimeout(toast.timer);
    toast.text = text;
    toast.timer = setTimeout(() => (toast.text = ''), ms);
}

export const comingSoon = (name) => showToast(`${name}은(는) 다음 업데이트에서 열려요`);
