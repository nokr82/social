<script setup>
/**
 * 번개 만들기 (디자인: BungaeCreate.dc.html)
 * - 광장에 연결되어 올라감 · 날짜 제한 없음(오늘·내일 칩 + 날짜 고르기)
 * - 장소: '만날 장소 고르기' 큰 카드 → 전체 화면 PlacePicker(지도 가운데 핀 + 검색, 카카오T 방식)
 *   → 대략 위치 자동 채움. 지도를 못 불러올 때만 '글로만 적기'. 고른 장소·메모는 참여 확정자만, 대략 위치는 모두에게
 * - 고를 수 있는 범위는 서버가 정함(주민 광장 5km·동네 광장 같은 구·자유광장 제한 없음), 생성 때 다시 확인
 * - 인원 나 포함 2~10명 · 나이 조건 선택
 */
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PlaceMap from '../components/PlaceMap.vue';
import PlacePicker from '../components/PlacePicker.vue';
import { ApiError, get, post } from '../lib/api';
import { meetupWhen } from '../lib/time';

const props = defineProps({ plazaId: { type: String, required: true } });
const router = useRouter();
const route = useRoute();

const plazaName = ref('');
const form = reactive({
    title: '', day: 'today', date: '', time: defaultTime(), area: '', place: '',
    capacity: 6, age: 'any', description: '',
});
const busy = ref(false);
const error = ref('');

// ── 장소 ──
const picked = ref(null);          // 고른 장소 {name, address, jibun, lat, lng, kakao_id|null}
const manual = ref(false);         // 지도를 못 불러와 '글로만 적기'를 고른 경우만
const areaTouched = ref(false);    // 대략 위치를 직접 고쳤으면 자동 채우기로 덮어쓰지 않음

// 장소 고르기 화면은 주소 ?pick=1로 연다 → 휴대폰 뒤로가기가 이 화면만 닫음 (PhotoViewer와 같은 방식)
const showPicker = computed(() => route.query.pick === '1');
let openedHere = false;

function openPicker() {
    openedHere = true;
    router.push({ query: { ...route.query, pick: '1' } });
}

function closePicker() {
    if (openedHere) {
        openedHere = false;
        router.back();
        return;
    }
    const { pick, ...rest } = route.query; // 새로고침으로 바로 열린 경우: 주소만 정리
    router.replace({ query: rest });
}

/** 지번 주소에서 동·읍·면 이름 → '역삼동 근처' (도로명 주소엔 동 이름이 없어서 지번 사용) */
function areaFrom(p) {
    const dong = (p.jibun || p.address || '').split(' ').find((w) => /(동|읍|면|가)$/.test(w) && !/^\d/.test(w));
    return dong ? `${dong} 근처` : '';
}

function onPicked(p) {
    picked.value = p;
    manual.value = false;
    if (!areaTouched.value || !form.area.trim()) form.area = areaFrom(p) || form.area;
    closePicker();
}

function onManual() {
    picked.value = null;
    manual.value = true;
    closePicker();
}

const AGES = [
    { id: 'any', label: '누구나' }, { id: '20s', label: '20대' },
    { id: '30s', label: '30대' }, { id: 'near', label: '내 나이 ±5세' },
];

/** 기본 시간: 지금부터 2시간 뒤 정각 */
function defaultTime() {
    const d = new Date(Date.now() + 2 * 3600e3);
    return `${String(d.getHours()).padStart(2, '0')}:00`;
}

/** 기기 시간대 기준 'YYYY-MM-DD' (date 입력의 min·기본값용 — toISOString은 UTC라 밤에 날짜가 하루 밀림) */
const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const todayYmd = ymd(new Date());
const dateInput = ref(null);

/** 오늘/내일 칩을 누르면 날짜 고르기는 해제, '날짜 선택'을 누르면 달력을 바로 엶 */
function pickDay(id) {
    form.day = id;
    if (id !== 'date') return;
    if (!form.date) { const t = new Date(); t.setDate(t.getDate() + 2); form.date = ymd(t); } // 모레부터 보여 줌
    dateInput.value?.showPicker?.(); // 지원 안 하는 브라우저는 아래 입력칸을 직접 누름
}

/** 오늘/내일/고른 날짜 + 시각 → Date (기기 시간대 기준) */
const startsAt = computed(() => {
    const [h, m] = form.time.split(':').map(Number);
    let d = new Date();
    if (form.day === 'tomorrow') d.setDate(d.getDate() + 1);
    if (form.day === 'date' && form.date) {
        const [y, mo, da] = form.date.split('-').map(Number);
        d = new Date(y, mo - 1, da); // new Date('YYYY-MM-DD')는 UTC로 해석되므로 숫자로 만듦
    }
    d.setHours(h, m, 0, 0);
    return d;
});
const timeError = computed(() => {
    if (form.day === 'date' && !form.date) return '날짜를 골라주세요';
    if (startsAt.value.getTime() - Date.now() < 10 * 60e3) return '지금부터 10분 이후 시간을 골라주세요';
    return '';
});
const whenHint = computed(() => (form.day === 'date' && form.date ? `${meetupWhen(startsAt.value.toISOString())}에 만나요` : '날짜 제한 없이 열 수 있어요'));
// 장소: 고른 장소가 있거나, 직접 입력이면 정확한 장소 글자가 있어야 함 (서버도 같은 규칙)
const placeOk = computed(() => (manual.value ? !!form.place.trim() : !!picked.value));
const valid = computed(() => form.title.trim().length >= 2 && form.area.trim() && placeOk.value && !timeError.value);

async function submit() {
    if (!valid.value || busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        const res = await post(`/plazas/${props.plazaId}/meetups`, {
            title: form.title.trim(),
            description: form.description.trim() || null,
            area_label: form.area.trim(),
            place_detail: form.place.trim() || null,
            place: !manual.value && picked.value ? {
                name: picked.value.name,
                address: picked.value.address,
                lat: picked.value.lat,
                lng: picked.value.lng,
                kakao_id: picked.value.kakao_id,
            } : null,
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
                <div class="row when">
                    <button v-for="d in [{ id: 'today', label: '오늘' }, { id: 'tomorrow', label: '내일' }, { id: 'date', label: '📅 날짜 선택' }]" :key="d.id" class="chip" :class="{ on: form.day === d.id }" :aria-pressed="form.day === d.id" @click="pickDay(d.id)">{{ d.label }}</button>
                    <label v-if="form.day === 'date'" class="time date">
                        <span class="sr-only">날짜</span>
                        <input ref="dateInput" v-model="form.date" type="date" :min="todayYmd">
                    </label>
                    <label class="time">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#5B4BD6" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>
                        <span class="sr-only">시간</span>
                        <input v-model="form.time" type="time" step="600">
                    </label>
                </div>
                <span class="hint" :class="{ err: timeError }">{{ timeError || whenHint }}</span>
            </div>

            <div class="field">
                <span class="label">어디서</span>

                <!-- 장소: 크게 눈에 띄는 카드 하나 → 전체 화면 장소 고르기 -->
                <div v-if="picked" class="picked">
                    <div class="picked-head">
                        <span class="col">
                            <b>{{ picked.name }}</b>
                            <small>{{ picked.address }}</small>
                        </span>
                        <button class="change" @click="openPicker">바꾸기</button>
                    </div>
                    <PlaceMap :lat="picked.lat" :lng="picked.lng" :height="120" />
                </div>
                <button v-else-if="!manual" class="pick-card" @click="openPicker">
                    <span class="pick-icon" aria-hidden="true">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z" /><circle cx="12" cy="9.5" r="2.5" /></svg>
                    </span>
                    <span class="col">
                        <b>만날 장소 고르기</b>
                        <small>지도에서 핀을 옮기거나 가게 이름으로 검색해요</small>
                    </span>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#5B4BD6" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6" /></svg>
                </button>
                <button v-else class="pick-again" @click="openPicker">지도에서 다시 고르기</button>

                <label class="sub">
                    <span>대략적인 위치 · 모두에게 보여요</span>
                    <input v-model="form.area" class="input" maxlength="40" placeholder="예) 역삼역 근처" @input="areaTouched = true">
                </label>
                <label class="sub">
                    <span class="lock">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#6B6785" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" /></svg>
                        {{ manual ? '정확한 장소' : '만날 곳 메모 (선택)' }} · 참여 확정자에게만 보여요
                    </span>
                    <input v-model="form.place" class="input" maxlength="100" :placeholder="manual ? '가게 이름이나 출구 번호' : '예) 2번 출구 앞, 창가 자리'">
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

        <PlacePicker v-if="showPicker" :plaza-id="plazaId" :initial="picked" @select="onPicked" @close="closePicker" @manual="onManual" />
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
/* 칩 3개 + 시각이 좁은 화면에서 넘치면 줄바꿈, 고른 날짜는 한 줄 전체 */
.row.when { flex-wrap: wrap; }
.row.when .time { min-width: 120px; }
.time.date { flex-basis: 100%; order: 3; }
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
.picked { border: 1.5px solid var(--c-primary); border-radius: 14px; padding: 12px; display: flex; flex-direction: column; gap: 10px; background: var(--c-surface); }
.picked b { font-size: 14px; color: var(--c-ink); }
.picked small { font-size: 12px; color: var(--c-muted); }
.picked-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.change { flex-shrink: 0; height: 36px; padding: 0 12px; border: 1.5px solid var(--c-line); border-radius: 18px; background: var(--c-surface); font-size: 13px; color: var(--c-ink-2); }
/* 장소 고르기: 폼에서 가장 눈에 띄게 (색 배경 + 아이콘 + 화살표) */
.pick-card { width: 100%; min-height: 72px; display: flex; align-items: center; gap: 12px; padding: 12px 14px; border: 2px dashed var(--c-primary); border-radius: 16px; background: var(--c-primary-soft); text-align: left; }
.pick-card .col { flex: 1; }
.pick-card b { font-size: 16px; color: var(--c-primary-deep); }
.pick-card small { font-size: 12px; color: var(--c-ink-2); }
.pick-icon { width: 44px; height: 44px; border-radius: 22px; background: var(--c-primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.pick-again { align-self: flex-start; height: 36px; padding: 0 14px; border: 1.5px solid var(--c-primary); border-radius: 18px; background: var(--c-surface); font-size: 13px; font-weight: 700; color: var(--c-primary-deep); }
.err-box { margin: 0; font-size: 13px; color: var(--c-danger); }
.bottom { position: absolute; left: 0; right: 0; bottom: 0; padding: 12px 20px 26px; background: var(--c-surface); border-top: 1px solid var(--c-divider); }
</style>
