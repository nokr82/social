<script setup>
/**
 * 번개 만들기 (디자인: BungaeCreate.dc.html)
 * - 광장에 연결되어 올라감 · 48시간 이내 약속만
 * - 대략 위치(공개) / 정확한 장소(참여 확정자만)
 * - 인원 나 포함 2~10명 · 나이 조건 선택
 */
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { ApiError, get, post } from '../lib/api';
import { showToast } from '../lib/toast';

const props = defineProps({ plazaId: { type: String, required: true } });
const router = useRouter();

const plazaName = ref('');
const form = reactive({
    title: '', day: 'today', time: defaultTime(), area: '', place: '',
    capacity: 6, age: 'any', description: '',
});
const busy = ref(false);
const error = ref('');

const AGES = [
    { id: 'any', label: '누구나' }, { id: '20s', label: '20대' },
    { id: '30s', label: '30대' }, { id: 'near', label: '내 나이 ±5세' },
];

/** 기본 시간: 지금부터 2시간 뒤 정각 */
function defaultTime() {
    const d = new Date(Date.now() + 2 * 3600e3);
    return `${String(d.getHours()).padStart(2, '0')}:00`;
}

/** 오늘/내일 + 시각 → Date (기기 시간대 기준) */
const startsAt = computed(() => {
    const [h, m] = form.time.split(':').map(Number);
    const d = new Date();
    if (form.day === 'tomorrow') d.setDate(d.getDate() + 1);
    d.setHours(h, m, 0, 0);
    return d;
});
const timeError = computed(() => {
    const diff = startsAt.value.getTime() - Date.now();
    if (diff < 10 * 60e3) return '지금부터 10분 이후 시간을 골라주세요';
    if (diff > 48 * 3600e3) return '48시간 이내 약속만 열 수 있어요';
    return '';
});
const valid = computed(() => form.title.trim().length >= 2 && form.area.trim() && form.place.trim() && !timeError.value);

async function submit() {
    if (!valid.value || busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        const res = await post(`/plazas/${props.plazaId}/meetups`, {
            title: form.title.trim(),
            description: form.description.trim() || null,
            area_label: form.area.trim(),
            place_detail: form.place.trim(),
            starts_at: startsAt.value.toISOString(), // UTC ISO — 서버가 시간대 포함해서 해석
            capacity: form.capacity,
            age_rule: form.age,
        });
        router.replace({ name: 'meetup', params: { id: res.data.id } });
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : '번개를 열지 못했어요';
    } finally {
        busy.value = false;
    }
}

onMounted(async () => {
    try { plazaName.value = (await get(`/plazas/${props.plazaId}`)).data.name; } catch { /* 이름 없이 진행 */ }
});
</script>

<template>
    <div class="create">
        <div class="head">
            <button class="icon" aria-label="닫기" @click="router.back()">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
            </button>
            <h1 class="title" style="font-size: 22px">번개 만들기</h1>
        </div>

        <div class="body scroll">
            <span v-if="plazaName" class="plaza-chip">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#5B4BD6" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z" /></svg>
                {{ plazaName }}에 올라가요
            </span>

            <label class="field">
                <span class="label">제목</span>
                <input v-model="form.title" class="input" maxlength="60" placeholder="예) 퇴근하고 역삼역에서 가볍게 한잔해요">
            </label>

            <div class="field">
                <span class="label">언제</span>
                <div class="row">
                    <button v-for="d in [{ id: 'today', label: '오늘' }, { id: 'tomorrow', label: '내일' }]" :key="d.id" class="chip" :class="{ on: form.day === d.id }" :aria-pressed="form.day === d.id" @click="form.day = d.id">{{ d.label }}</button>
                    <label class="time">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#5B4BD6" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>
                        <span class="sr-only">시간</span>
                        <input v-model="form.time" type="time" step="600">
                    </label>
                </div>
                <span class="hint" :class="{ err: timeError }">{{ timeError || '번개는 48시간 이내 약속만 열 수 있어요' }}</span>
            </div>

            <div class="field">
                <span class="label">어디서</span>
                <label class="sub">
                    <span>대략적인 위치 · 모두에게 보여요</span>
                    <input v-model="form.area" class="input" maxlength="40" placeholder="예) 역삼역 근처">
                </label>
                <label class="sub">
                    <span class="lock">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#6B6785" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" /></svg>
                        정확한 장소 · 참여 확정자에게만 보여요
                    </span>
                    <input v-model="form.place" class="input" maxlength="100" placeholder="가게 이름이나 출구 번호">
                </label>
            </div>

            <div class="count-row">
                <span class="col">
                    <span class="label">최대 인원</span>
                    <small>나 포함 2~10명</small>
                </span>
                <div class="stepper">
                    <button class="minus" aria-label="인원 줄이기" :disabled="form.capacity <= 2" @click="form.capacity--">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#5B4BD6" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14" /></svg>
                    </button>
                    <span aria-live="polite">{{ form.capacity }}</span>
                    <button class="plus" aria-label="인원 늘리기" :disabled="form.capacity >= 10" @click="form.capacity++">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                    </button>
                </div>
            </div>

            <div class="field">
                <span class="label">참여 조건 <small>(선택)</small></span>
                <div class="row wrap">
                    <button v-for="a in AGES" :key="a.id" class="chip sm" :class="{ on: form.age === a.id }" :aria-pressed="form.age === a.id" @click="form.age = a.id">{{ a.label }}</button>
                </div>
                <span class="hint">조건은 본인인증 정보로 서버가 확인해요. 참여자의 나이는 공개되지 않아요.</span>
            </div>

            <label class="field">
                <span class="label">소개 <small>(선택)</small></span>
                <textarea v-model="form.description" rows="3" maxlength="500" placeholder="어떤 분위기인지 알려주세요" />
            </label>

            <p v-if="error" class="err-box" role="alert">{{ error }}</p>
        </div>

        <div class="bottom">
            <button class="btn-primary" :disabled="!valid || busy" @click="submit">번개 열기</button>
        </div>
    </div>
</template>

<style scoped>
.create { height: 100%; display: flex; flex-direction: column; position: relative; }
.head { display: flex; align-items: center; gap: 4px; padding: 14px 12px 4px 8px; }
.icon { width: 44px; height: 44px; border: none; background: none; display: flex; align-items: center; justify-content: center; }
.body { flex: 1; min-height: 0; padding: 8px 20px 120px; display: flex; flex-direction: column; gap: 20px; }
.plaza-chip { align-self: flex-start; display: inline-flex; align-items: center; gap: 6px; background: var(--c-primary-soft); color: var(--c-primary-deep); font-size: 12px; font-weight: 700; padding: 5px 10px; border-radius: 10px; }
.field { display: flex; flex-direction: column; gap: 8px; }
.label { font-size: 14px; font-weight: 700; }
.label small { font-weight: 500; color: var(--c-muted); font-size: 12px; }
.input { height: 52px; border: 1.5px solid var(--c-line); border-radius: 14px; padding: 0 14px; font-size: 15px; color: var(--c-ink); background: var(--c-surface); outline: none; }
.input:focus, textarea:focus { border-color: var(--c-primary); }
textarea { border: 1.5px solid var(--c-line); border-radius: 14px; padding: 12px 14px; font-size: 14px; color: var(--c-ink); background: var(--c-surface); outline: none; resize: none; }
.row { display: flex; gap: 8px; }
.row.wrap { flex-wrap: wrap; }
.chip { height: 42px; padding: 0 18px; border: 1.5px solid var(--c-line); border-radius: 21px; background: var(--c-surface); color: var(--c-ink-2); font-size: 14px; font-weight: 500; flex-shrink: 0; }
.chip.sm { height: 40px; padding: 0 14px; font-size: 13px; }
.chip.on { border-color: var(--c-primary); background: var(--c-primary); color: #fff; font-weight: 700; }
.time { flex: 1; height: 42px; border: 1.5px solid var(--c-line); border-radius: 21px; background: var(--c-surface); display: flex; align-items: center; padding: 0 14px; gap: 6px; }
.time input { flex: 1; min-width: 0; border: none; outline: none; background: transparent; font-size: 14px; color: var(--c-ink); }
.hint { font-size: 12px; color: var(--c-muted); }
.hint.err { color: var(--c-danger); }
.sub { display: flex; flex-direction: column; gap: 4px; }
.sub > span { font-size: 12px; color: var(--c-muted); }
.lock { display: flex; align-items: center; gap: 4px; }
.count-row { display: flex; align-items: center; justify-content: space-between; }
.col { display: flex; flex-direction: column; gap: 2px; }
.col small { font-size: 12px; color: var(--c-muted); }
.stepper { display: flex; align-items: center; gap: 4px; background: var(--c-surface); border-radius: 22px; padding: 2px; }
.stepper button { width: 44px; height: 44px; border: none; border-radius: 22px; display: flex; align-items: center; justify-content: center; }
.stepper button:disabled { opacity: .4; }
.minus { background: var(--c-primary-soft); }
.plus { background: var(--c-primary); }
.stepper span { width: 40px; text-align: center; font-size: 18px; font-weight: 700; }
.err-box { margin: 0; font-size: 13px; color: var(--c-danger); }
.bottom { position: absolute; left: 0; right: 0; bottom: 0; padding: 12px 20px 26px; background: var(--c-surface); border-top: 1px solid var(--c-divider); }
</style>
