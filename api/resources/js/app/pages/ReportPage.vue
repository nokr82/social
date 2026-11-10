<script setup>
/**
 * 신고·차단 (디자인: Report.dc.html)
 * sheet(메뉴) → reason(사유) → done(접수)
 * sheet(메뉴) → block(확인) → blocked(완료) → '신고도 하기'로 reason 가능
 *
 * 신고·차단 모두 상대에게 알리지 않는다 (서버도 알림 없음).
 */
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AvatarFace from '../components/AvatarFace.vue';
import { ApiError, get, post } from '../lib/api';
import { showToast } from '../lib/toast';

const props = defineProps({ userId: { type: String, required: true } });
const route = useRoute();
const router = useRouter();

// 대상 정보는 이전 화면(광장 미니 카드)이 query로 넘겨준다
const target = computed(() => ({
    nickname: route.query.nickname ?? '사용자',
    avatar: { color: route.query.color, face: route.query.face },
}));

const view = ref('sheet');
const reasons = ref([]);
const reason = ref(null);
const detail = ref('');
const blockToo = ref(true);       // 디자인: 기본 ON
const alreadyBlocked = ref(false); // 차단 완료 화면에서 '신고도 하기'로 온 경우
const busy = ref(false);

onMounted(async () => {
    reasons.value = (await get('/meta/report-reasons')).data;
});

async function submitReport() {
    if (!reason.value || busy.value) return;
    busy.value = true;
    try {
        await post('/reports', {
            user_id: Number(props.userId),
            target_type: 'user',
            reason: reason.value,
            detail: detail.value.trim() || null,
            block: blockToo.value || alreadyBlocked.value,
        });
        view.value = 'done';
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '신고를 보내지 못했어요');
    } finally {
        busy.value = false;
    }
}

async function doBlock() {
    if (busy.value) return;
    busy.value = true;
    try {
        await post('/blocks', { user_id: Number(props.userId) });
        alreadyBlocked.value = true;
        view.value = 'blocked';
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '차단하지 못했어요');
    } finally {
        busy.value = false;
    }
}

function cancel() {
    // 광장에서 왔으면 광장으로, 아니면 이전 화면으로
    if (route.query.from) router.replace({ name: 'plaza', params: { id: route.query.from } });
    else router.back();
}

const goMain = () => router.replace({ name: 'main' });
</script>

<template>
    <div class="report">
        <!-- 배경: 상대 (시트·차단 확인일 때) -->
        <template v-if="view === 'sheet' || view === 'block'">
            <div class="bg" aria-hidden="true">
                <span class="bg-face"><AvatarFace :parts="target.avatar" :size="84" /></span>
                <span class="title" style="font-size: 24px">{{ target.nickname }}</span>
            </div>
            <div class="dim" aria-hidden="true" @click="view === 'sheet' && cancel()" />
        </template>

        <!-- 1) 메뉴 시트 -->
        <div v-if="view === 'sheet'" class="sheet" role="dialog" :aria-label="`${target.nickname}님 메뉴`">
            <div class="grip" />
            <button class="row danger" @click="view = 'reason'">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#C0392B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 21V4h11l-1 4 4 0-1 6H5" /></svg>
                신고하기
            </button>
            <button class="row" @click="view = 'block'">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M5.6 5.6l12.8 12.8" /></svg>
                차단하기
            </button>
            <button class="cancel" @click="cancel">취소</button>
        </div>

        <!-- 2) 신고 사유 -->
        <div v-else-if="view === 'reason'" class="page">
            <div class="head">
                <button class="icon" aria-label="뒤로" @click="view = alreadyBlocked ? 'blocked' : 'sheet'">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
                </button>
                <h1 class="title" style="font-size: 22px">{{ target.nickname }}님 신고</h1>
            </div>

            <div class="content scroll">
                <div class="warn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#B03024" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex-shrink: 0; margin-top: 1px"><path d="M12 3l9 16H3z" /><path d="M12 10v4M12 17h.01" /></svg>
                    <span><b>지금 위험한 상황이라면</b> 앱 신고보다 <b>112</b>에 먼저 연락하세요.</span>
                </div>

                <fieldset class="reasons" role="radiogroup">
                    <legend>어떤 문제인가요?</legend>
                    <button
                        v-for="r in reasons" :key="r.code"
                        role="radio" :aria-checked="reason === r.code" :class="{ on: reason === r.code }"
                        @click="reason = r.code"
                    >
                        <span class="radio" aria-hidden="true"><span /></span>
                        {{ r.label }}
                    </button>
                </fieldset>

                <label class="field">
                    <span class="label">자세한 내용 <small>(선택)</small></span>
                    <textarea v-model="detail" rows="3" maxlength="500" placeholder="언제, 어디서 무슨 일이 있었는지 적어주면 검토가 빨라져요" />
                </label>
                <span class="hint">해당 사용자와의 대화·사진이 검토용으로 함께 전달돼요.</span>

                <button v-if="!alreadyBlocked" class="check" role="checkbox" :aria-checked="blockToo" @click="blockToo = !blockToo">
                    <span class="box" :class="{ on: blockToo }" aria-hidden="true">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7" /></svg>
                    </span>
                    {{ target.nickname }}님 차단도 함께하기
                </button>
            </div>

            <div class="bottom">
                <button class="btn-primary submit" :disabled="!reason || busy" @click="submitReport">
                    {{ reason ? '신고하기' : '사유를 선택해 주세요' }}
                </button>
            </div>
        </div>

        <!-- 3) 신고 완료 -->
        <div v-else-if="view === 'done'" class="result">
            <div class="center">
                <span class="badge ok">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#1E7A4C" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5L20 7" /></svg>
                </span>
                <h1 class="title" style="font-size: 26px">신고가 접수됐어요</h1>
                <p>알려줘서 고마워요. 광장을 안전하게 만드는 데 큰 도움이 돼요.</p>
                <div class="steps">
                    <span><b>1</b> 운영팀이 24시간 안에 검토해요</span>
                    <span><b>2</b> 결과는 알림으로 알려드려요</span>
                    <span><b>3</b> 신고한 사실은 상대에게 알리지 않아요</span>
                    <span v-if="blockToo || alreadyBlocked"><b>4</b> {{ target.nickname }}님은 차단됐어요</span>
                </div>
            </div>
            <button class="btn-primary" @click="goMain">확인</button>
        </div>

        <!-- 4) 차단 확인 -->
        <div v-if="view === 'block'" class="confirm" role="alertdialog" aria-label="차단 확인">
            <h2>{{ target.nickname }}님을 차단할까요?</h2>
            <div class="facts">
                <span>· 서로 프로필과 광장에서 보이지 않아요</span>
                <span>· 친구 관계와 대화가 끊겨요</span>
                <span>· 같은 번개에 참여할 수 없어요</span>
                <span>· 상대에게 차단 사실을 알리지 않아요</span>
            </div>
            <span class="hint center-text">MY › 차단 목록에서 언제든 해제할 수 있어요</span>
            <div class="btns">
                <button class="ghost" @click="view = 'sheet'">취소</button>
                <button class="dark" :disabled="busy" @click="doBlock">차단</button>
            </div>
        </div>

        <!-- 5) 차단 완료 -->
        <div v-if="view === 'blocked'" class="result">
            <div class="center">
                <span class="badge">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#3F31B0" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M5.6 5.6l12.8 12.8" /></svg>
                </span>
                <h1 class="title" style="font-size: 26px">차단했어요</h1>
                <p>이제 {{ target.nickname }}님과 서로 보이지 않아요.<br>불편한 일이 있었다면 신고도 해주세요.</p>
                <button class="link" @click="view = 'reason'">신고도 하기</button>
            </div>
            <button class="btn-primary" @click="goMain">확인</button>
        </div>
    </div>
</template>

<style scoped>
.report { height: 100%; position: relative; overflow: hidden; }

.bg { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; gap: 10px; padding-top: 80px; }
.bg-face { width: 96px; height: 96px; border-radius: 48px; background: var(--c-surface); display: flex; align-items: center; justify-content: center; }
.dim { position: absolute; inset: 0; background: rgba(31,27,58,.45); }

.sheet { position: absolute; left: 0; right: 0; bottom: 0; background: var(--c-surface); border-radius: 28px 28px 0 0; padding: 10px 20px 30px; display: flex; flex-direction: column; gap: 6px; }
.grip { align-self: center; width: 40px; height: 5px; border-radius: 3px; background: var(--c-line); margin-bottom: 8px; }
.row { height: 58px; border: none; background: var(--c-bg); border-radius: 16px; display: flex; align-items: center; gap: 12px; padding: 0 16px; font-size: 16px; font-weight: 700; color: var(--c-ink); }
.row.danger { color: var(--c-danger); }
.cancel { height: 54px; margin-top: 4px; border: none; background: none; border-radius: 16px; font-size: 15px; font-weight: 700; color: var(--c-ink-2); }

.page { position: absolute; inset: 0; display: flex; flex-direction: column; }
.head { display: flex; align-items: center; gap: 4px; padding: 14px 12px 4px 8px; }
.icon { width: 44px; height: 44px; border: none; background: none; display: flex; align-items: center; justify-content: center; }
.content { flex: 1; min-height: 0; padding: 8px 20px 120px; display: flex; flex-direction: column; gap: 14px; }
.warn { display: flex; gap: 8px; background: #FDECEA; border-radius: 14px; padding: 10px 12px; font-size: 13px; color: #8A2318; line-height: 1.5; }

.reasons { border: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.reasons legend { font-size: 15px; font-weight: 700; margin-bottom: 8px; padding: 0; }
.reasons button { min-height: 52px; border: 2px solid var(--c-surface); background: var(--c-surface); border-radius: 14px; display: flex; align-items: center; gap: 12px; padding: 0 14px; text-align: left; font-size: 15px; color: var(--c-ink); }
.reasons button.on { border-color: var(--c-primary); }
.radio { width: 22px; height: 22px; border-radius: 11px; border: 2px solid #CFC6FF; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.radio span { width: 10px; height: 10px; border-radius: 5px; }
.on .radio { border-color: var(--c-primary); }
.on .radio span { background: var(--c-primary); }

.field { display: flex; flex-direction: column; gap: 8px; }
.label { font-size: 14px; font-weight: 700; }
.label small { font-weight: 500; color: var(--c-muted); font-size: 12px; }
textarea { border: 1.5px solid var(--c-line); border-radius: 14px; padding: 12px 14px; font-size: 14px; color: var(--c-ink); background: var(--c-surface); outline: none; resize: none; }
textarea:focus { border-color: var(--c-primary); }
.hint { font-size: 12px; color: var(--c-muted); }
.center-text { text-align: center; }

.check { border: none; background: var(--c-surface); border-radius: 14px; min-height: 52px; display: flex; align-items: center; gap: 10px; padding: 0 14px; text-align: left; font-size: 14px; color: var(--c-ink); }
.box { width: 22px; height: 22px; border-radius: 6px; background: var(--c-surface); border: 2px solid #CFC6FF; display: flex; align-items: center; justify-content: center; }
.box.on { background: var(--c-primary); border-color: var(--c-primary); }

.bottom { position: absolute; left: 0; right: 0; bottom: 0; padding: 12px 20px 26px; background: var(--c-surface); border-top: 1px solid var(--c-divider); }
.submit:not(:disabled) { background: var(--c-danger); }

.result { position: absolute; inset: 0; display: flex; flex-direction: column; padding: 0 24px 40px; background: var(--c-bg); }
.center { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 14px; text-align: center; }
.center p { margin: 0; font-size: 14px; color: var(--c-ink-2); line-height: 1.6; }
.badge { width: 80px; height: 80px; border-radius: 40px; background: #ECE8FF; display: flex; align-items: center; justify-content: center; }
.badge.ok { background: #E6F8EF; }
.steps { width: 100%; background: var(--c-surface); border-radius: 18px; padding: 14px 16px; display: flex; flex-direction: column; gap: 10px; text-align: left; font-size: 13px; color: var(--c-ink-2); margin-top: 8px; }
.steps span { display: flex; gap: 8px; }
.steps b { color: var(--c-primary-ink); }
.link { border: none; background: none; color: var(--c-primary-ink); font-size: 14px; font-weight: 700; padding: 12px; }

.confirm { position: absolute; left: 24px; right: 24px; top: 200px; background: var(--c-surface); border-radius: 24px; padding: 22px 20px 18px; display: flex; flex-direction: column; gap: 14px; box-shadow: 0 12px 32px rgba(31,27,58,.25); }
.confirm h2 { margin: 0; font-size: 18px; font-weight: 700; text-align: center; }
.facts { display: flex; flex-direction: column; gap: 8px; font-size: 13px; color: var(--c-ink-2); background: var(--c-bg); border-radius: 14px; padding: 12px 14px; line-height: 1.5; }
.btns { display: flex; gap: 8px; }
.btns button { flex: 1; height: 50px; border-radius: 14px; font-size: 15px; font-weight: 700; }
.ghost { border: 1.5px solid var(--c-line); background: var(--c-surface); color: var(--c-ink-2); }
.dark { border: none; background: var(--c-ink); color: #fff; }
</style>
