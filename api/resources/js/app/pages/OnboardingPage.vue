<script setup>
/**
 * 온보딩 (디자인: Onboarding.dc.html)
 * 0 시작 → 1 본인인증 → 2 아바타·닉네임 → 3 대표사진
 *
 * 서버 흐름과의 대응:
 *   0 '시작하기'  = POST /auth/social   (기존 회원이면 바로 메인)
 *   1 본인인증    = POST /auth/identity (성별·나이 표시)
 *   2 다음        = POST /auth/register (계정 생성 + 토큰)
 *   3 대표사진    = POST /me/photos (필수, 항상 공개)
 *
 * 모드는 서버 설정(GET /meta/auth)을 따른다
 *   social  : real → 카카오 로그인 화면으로 이동 후 /auth/kakao?code= 로 돌아옴 | fake → 버튼만 누르면 진행
 *   identity: self → 성별·생년월일 직접 입력 + 만 19세 확인 (비공개 베타, '인증 전' 표시)
 *             fake → 개발용 시트 | pass → (대행사 연동 시)
 */
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AvatarFace from '../components/AvatarFace.vue';
import { ApiError, get, post, upload } from '../lib/api';
import { auth } from '../lib/auth';
import { AVATAR_COLORS, AVATAR_FACES } from '../lib/avatar';
import { uploadPhoto } from '../lib/image';
import { showToast } from '../lib/toast';

const router = useRouter();
const route = useRoute();
const authMeta = ref({ social: 'fake', identity: 'fake', kakao: {} }); // 서버 설정을 받기 전 기본값
const KAKAO_STATE_KEY = 'social.kakaoState';

const step = ref(0);
const busy = ref(false);
const signupToken = ref(null);
const identity = ref(null);            // { gender, age }
const avatar = reactive({ color: 'apricot', face: 'smile' });
const nickname = ref('');
const nicknameError = ref('');
const photos = ref([]);         // 올린 사진 (첫 장 = 대표)
const fileInput = ref(null);

// 개발용 본인인증 입력 시트 (PASS 대행사 연동 전)
const devSheet = reactive({ open: false, gender: 'F', birth: '1997-05-05' });
// 베타 직접 입력 (IDENTITY_DRIVER=self)
const selfForm = reactive({ gender: '', birth: '', adult: false });
const selfMode = computed(() => authMeta.value.identity === 'self');
const selfValid = computed(() => selfForm.gender && /^\d{4}-\d{2}-\d{2}$/.test(selfForm.birth) && selfForm.adult);

const bars = computed(() => [1, 2, 3].map((i) => i <= step.value));
const nicknameValid = computed(() => /^[가-힣a-zA-Z0-9]{2,10}$/.test(nickname.value));

/** 이 브라우저의 개발용 소셜 ID (같은 브라우저면 같은 계정으로 재로그인) */
function devSocialToken() {
    let id = localStorage.getItem('social.devId');
    if (!id) {
        id = `web-${crypto.randomUUID?.() ?? Math.random().toString(36).slice(2)}`;
        localStorage.setItem('social.devId', id);
    }
    return `fake:${id}`;
}

async function run(fn) {
    if (busy.value) return;
    busy.value = true;
    try {
        await fn();
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '네트워크를 확인해 주세요');
    } finally {
        busy.value = false;
    }
}

/** 0 → 1: 소셜 로그인 */
const start = () => run(async () => {
    if (authMeta.value.social !== 'fake') return goKakao();
    await afterSocial(await post('/auth/social', { provider: 'kakao', token: devSocialToken() }));
});

/**
 * 카카오 로그인 화면으로 이동.
 * state: 우리가 보낸 요청에 대한 응답인지 확인하는 일회용 값 (다른 사이트가 꾸며 보낸 code 차단)
 */
function goKakao() {
    const { client_id: clientId, redirect_uri: redirectUri } = authMeta.value.kakao ?? {};
    if (!clientId) return showToast('카카오 로그인 설정이 아직 안 됐어요');
    const state = crypto.randomUUID?.() ?? Math.random().toString(36).slice(2);
    sessionStorage.setItem(KAKAO_STATE_KEY, state);
    const q = new URLSearchParams({ response_type: 'code', client_id: clientId, redirect_uri: redirectUri, state });
    window.location.href = `https://kauth.kakao.com/oauth/authorize?${q}`;
}

/** 카카오에서 돌아온 경우 (/auth/kakao?code=...&state=...) */
async function handleKakaoReturn() {
    const { code, state, error } = route.query;
    // 주소창에서 code 제거 (새로고침·공유로 재사용되지 않게). 같은 화면이 다시 그려지지 않도록 라우터 대신 history만 교체
    window.history.replaceState(window.history.state, '', '/onboarding');
    const expected = sessionStorage.getItem(KAKAO_STATE_KEY);
    sessionStorage.removeItem(KAKAO_STATE_KEY);
    if (error) return showToast('카카오 로그인을 취소했어요');
    if (!code || !state || state !== expected) return showToast('로그인 요청이 올바르지 않아요. 다시 시도해 주세요');
    await run(async () => afterSocial(await post('/auth/social', { provider: 'kakao', token: code })));
}

/** 소셜 로그인 결과: 기존 회원 → 메인, 신규 → 본인인증 단계 */
async function afterSocial(res) {
    if (res.status === 'login') {
        auth.setToken(res.token);
        auth.me = res.user;
        return router.replace({ name: 'main' });
    }
    signupToken.value = res.signup_token;
    // 전에 본인인증까지 마치고 나갔던 계정 → 인증 단계를 건너뛰고 닉네임부터
    if (res.identity) {
        identity.value = { gender: res.identity.gender, age: res.identity.age };
        step.value = 2;
        showToast('본인인증은 이미 완료됐어요. 이어서 가입해요');
        return;
    }
    step.value = 1;
}

/** 1(베타): 직접 입력 */
const verifySelf = () => run(async () => {
    const res = await post('/auth/identity', {
        signup_token: signupToken.value,
        gender: selfForm.gender,
        birth_date: selfForm.birth,
        adult: selfForm.adult,
    });
    identity.value = { gender: res.gender, age: res.age };
});

onMounted(async () => {
    try {
        authMeta.value = await get('/meta/auth');
    } catch { /* 기본값(개발 모드)으로 진행 */ }
    if (route.name === 'kakao-callback') await handleKakaoReturn();
});

/** 1: 본인인증 (개발용 시트에서 성별·생년월일 입력) */
const verify = () => run(async () => {
    const ymd = devSheet.birth.replaceAll('-', '');
    // CI는 사람마다 고유 → 개발 중엔 무작위로 만들어 매번 '새 사람'으로 인증
    const ci = `dev-${Math.random().toString(36).slice(2, 12)}`;
    const res = await post('/auth/identity', {
        signup_token: signupToken.value,
        transaction_id: `fake:${ci}:${devSheet.gender}:${ymd}`,
    });
    identity.value = { gender: res.gender, age: res.age };
    devSheet.open = false;
});

/** 2 → 3: 계정 생성 */
const register = () => run(async () => {
    nicknameError.value = '';
    try {
        const res = await post('/auth/register', {
            signup_token: signupToken.value,
            nickname: nickname.value,
            avatar: { color: avatar.color, face: avatar.face },
        });
        auth.setToken(res.token);
        auth.me = res.user;
        step.value = 3;
    } catch (e) {
        // 닉네임 중복·형식 오류는 입력칸 아래에 표시
        if (e instanceof ApiError && e.errors?.nickname) {
            nicknameError.value = e.errors.nickname[0];
            return;
        }
        // 가입 시간(15분) 초과 → 처음부터
        if (e instanceof ApiError && e.code === 'SIGNUP_EXPIRED') step.value = 0;
        throw e;
    }
});

/** 3: 사진 업로드 (첫 장이 대표사진) */
async function onPick(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    await run(async () => {
        photos.value.push(await uploadPhoto(file, upload));
    });
}

function back() {
    // 계정이 만들어진 뒤(3단계)에는 되돌아가지 않음
    if (step.value === 3) return;
    step.value = Math.max(0, step.value - 1);
}
</script>

<template>
    <div class="onb">
        <!-- 상단: 뒤로 + 진행 막대 -->
        <div v-if="step > 0" class="top">
            <button class="icon-btn" aria-label="이전 단계" :disabled="step === 3" @click="back">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
            </button>
            <div class="bars" role="progressbar" aria-label="가입 진행" aria-valuemin="1" aria-valuemax="3" :aria-valuenow="step">
                <div v-for="(on, i) in bars" :key="i" :class="{ on }" />
            </div>
            <span class="count">{{ step }}/3</span>
        </div>

        <!-- 0. 시작 -->
        <section v-if="step === 0" class="pane pane-start">
            <div class="hero">
                <div class="hero-art" aria-hidden="true">
                    <div class="hero-ground" />
                    <AvatarFace :parts="{ color: 'mint' }" :size="64" style="left: 30px; top: 50px" />
                    <AvatarFace :parts="{ color: 'apricot' }" :size="72" style="left: 88px; top: 20px" />
                    <AvatarFace :parts="{ color: 'sky' }" :size="60" style="left: 150px; top: 56px" />
                </div>
                <h1 class="title" style="font-size: 32px">우리 동네 광장에서<br>만나요</h1>
                <p class="lead">근처 사람들과 아바타로 어울리고,<br>마음 맞으면 친구가 돼요</p>
            </div>
            <button class="btn-primary" :class="{ kakao: authMeta.social !== 'fake' }" :disabled="busy" @click="start">
                {{ authMeta.social !== 'fake' ? '카카오로 시작하기' : '본인인증으로 시작하기' }}
            </button>
            <p class="foot">만 19세 이상만 가입할 수 있어요</p>
        </section>

        <!-- 1. 본인인증 -->
        <section v-else-if="step === 1" class="pane">
            <div class="head">
                <h1 class="title h">{{ selfMode ? '성별과 나이를 알려주세요' : '본인인증을 해주세요' }}</h1>
                <p class="lead">{{ selfMode ? '베타 기간에는 본인인증 대신 직접 입력해요.' : '성별과 나이는 인증 정보로 자동 입력되고' }}<br>친구가 된 사람에게만 보여요</p>
            </div>

            <!-- 베타: 직접 입력 -->
            <form v-if="selfMode && !identity" class="self-form" @submit.prevent="verifySelf">
                <div class="field">
                    <span class="label">성별</span>
                    <div class="row">
                        <button type="button" class="chip" :class="{ on: selfForm.gender === 'F' }" :aria-pressed="selfForm.gender === 'F'" @click="selfForm.gender = 'F'">여</button>
                        <button type="button" class="chip" :class="{ on: selfForm.gender === 'M' }" :aria-pressed="selfForm.gender === 'M'" @click="selfForm.gender = 'M'">남</button>
                    </div>
                </div>
                <label class="field">
                    <span class="label">생년월일</span>
                    <input v-model="selfForm.birth" class="input" type="date" max="2010-12-31" required>
                </label>
                <label class="adult">
                    <input v-model="selfForm.adult" type="checkbox">
                    <span>만 19세 이상이며, 사실과 다르게 입력하면 이용이 제한될 수 있음을 확인해요</span>
                </label>
                <button class="btn-primary" type="submit" :disabled="!selfValid || busy">확인</button>
            </form>

            <button v-else-if="!identity" class="verify-btn" @click="devSheet.open = true">
                <span class="verify-ic">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#5B4BD6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="2" width="12" height="20" rx="3" /><path d="M11 18h2" /></svg>
                </span>
                <span class="verify-txt">
                    <b>휴대폰 본인인증</b>
                    <small>통신사 인증 (PASS 등)</small>
                </span>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#8A85A3" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6" /></svg>
            </button>

            <div v-else class="verified">
                <span class="ok">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#1E7A4C" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5L20 7" /></svg>
                    {{ selfMode ? '입력 완료 · 프로필에 인증 전으로 표시돼요' : '인증 완료' }}
                </span>
                <div class="facts">
                    <div><small>성별</small><b>{{ identity.gender === 'F' ? '여' : '남' }}</b></div>
                    <div><small>나이</small><b>만 {{ identity.age }}세</b></div>
                </div>
                <span class="lock">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#6B6785" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" /></svg>
                    수정할 수 없어요 · 친구에게만 공개
                </span>
            </div>

            <div class="grow" />
            <div class="note">만 19세 미만은 가입할 수 없어요. {{ selfMode ? '정식 출시 때 휴대폰 본인인증으로 다시 확인해요.' : '인증 정보는 성별·나이 확인과 1인 1계정 확인에만 써요.' }}</div>
            <button class="btn-primary" :disabled="!identity" @click="step = 2">다음</button>
        </section>

        <!-- 2. 아바타 만들기 -->
        <section v-else-if="step === 2" class="pane">
            <h1 class="title h">광장에서 쓸<br>아바타를 만들어요</h1>

            <div class="preview">
                <AvatarFace :parts="avatar" :size="132" role="img" aria-label="내 아바타 미리보기" />
            </div>

            <div class="field">
                <span class="label">색</span>
                <div class="row">
                    <button
                        v-for="c in AVATAR_COLORS" :key="c.code"
                        class="swatch" :class="{ on: avatar.color === c.code }"
                        :aria-label="`${c.label} 색`" :aria-pressed="avatar.color === c.code"
                        @click="avatar.color = c.code"
                    >
                        <span :style="{ background: c.hex }" />
                    </button>
                </div>
            </div>

            <div class="field">
                <span class="label">표정</span>
                <div class="row">
                    <button
                        v-for="f in AVATAR_FACES" :key="f.code"
                        class="chip" :class="{ on: avatar.face === f.code }" :aria-pressed="avatar.face === f.code"
                        @click="avatar.face = f.code"
                    >{{ f.label }}</button>
                </div>
            </div>

            <label class="field">
                <span class="label">닉네임</span>
                <input v-model.trim="nickname" class="input" type="text" maxlength="10" placeholder="광장에서 불릴 이름 (2~10자)" :aria-invalid="!!nicknameError">
                <span v-if="nicknameError" class="err">{{ nicknameError }}</span>
            </label>

            <div class="grow" />
            <button class="btn-primary" :disabled="!nicknameValid || busy" @click="register">다음</button>
        </section>

        <!-- 3. 대표사진 (사진 기능 2차) -->
        <section v-else class="pane">
            <div class="head">
                <h1 class="title h">대표사진을 올려주세요</h1>
                <p class="lead">대표사진은 모두에게 보여요.<br>나머지는 나중에 MY에서 공개/비공개를 정할 수 있어요.</p>
            </div>

            <div class="photos">
                <div v-for="(p, i) in photos" :key="p.id" class="photo filled">
                    <img :src="p.url" :alt="i === 0 ? '대표사진' : `사진 ${i + 1}`">
                    <span v-if="i === 0" class="main-tag">대표 · 공개</span>
                </div>
                <button
                    v-for="i in Math.max(0, 3 - photos.length)" :key="`add-${i}`"
                    class="photo" :class="{ main: photos.length === 0 && i === 1 }"
                    :aria-label="photos.length === 0 && i === 1 ? '대표사진 추가 (필수)' : '사진 추가'"
                    :disabled="busy" @click="fileInput.click()"
                >
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" :stroke="photos.length === 0 && i === 1 ? '#5B4BD6' : '#8A85A3'" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                    <template v-if="photos.length === 0 && i === 1">{{ busy ? '올리는 중…' : '대표사진' }}</template>
                </button>
            </div>
            <input ref="fileInput" type="file" accept="image/*" hidden @change="onPick">

            <div class="guide">
                <b>사진 가이드</b>
                <span>· 얼굴이 잘 보이는 본인 사진이면 좋아요</span>
                <span>· 타인 사진, 선정적인 사진은 검수 후 삭제돼요</span>
            </div>

            <div class="grow" />
            <button class="btn-primary" :disabled="!photos.length" @click="router.replace({ name: 'main' })">광장으로 가기</button>
        </section>

        <!-- 개발용 본인인증 시트 -->
        <div v-if="devSheet.open" class="dim" @click.self="devSheet.open = false">
            <div class="sheet" role="dialog" aria-label="개발용 본인인증">
                <div class="grip" />
                <b>개발용 본인인증</b>
                <small class="muted">PASS 연동 전 테스트용이에요. 운영에서는 통신사 인증 화면이 열려요.</small>
                <div class="row">
                    <button class="chip" :class="{ on: devSheet.gender === 'F' }" @click="devSheet.gender = 'F'">여</button>
                    <button class="chip" :class="{ on: devSheet.gender === 'M' }" @click="devSheet.gender = 'M'">남</button>
                </div>
                <label class="field">
                    <span class="label">생년월일</span>
                    <input v-model="devSheet.birth" class="input" type="date">
                </label>
                <button class="btn-primary" :disabled="busy" @click="verify">인증하기</button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.onb { height: 100%; display: flex; flex-direction: column; position: relative; }
.top { display: flex; align-items: center; gap: 8px; padding: 14px 20px 0 8px; }
.icon-btn { width: 44px; height: 44px; border: none; background: none; display: flex; align-items: center; justify-content: center; }
.icon-btn:disabled { opacity: .3; }
.bars { flex: 1; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; }
.bars div { height: 6px; border-radius: 3px; background: var(--c-line); }
.bars div.on { background: var(--c-primary); }
.count { font-size: 12px; font-weight: 700; color: var(--c-muted); min-width: 28px; text-align: right; }

.pane { flex: 1; display: flex; flex-direction: column; padding: 28px 24px 40px; gap: 18px; min-height: 0; overflow-y: auto; }
.pane-start { padding-top: 0; gap: 0; }
.hero { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 18px; text-align: center; }
.hero-art { position: relative; width: 240px; height: 170px; }
.hero-art :deep(svg) { position: absolute; }
.hero-ground { position: absolute; left: 20px; top: 70px; width: 200px; height: 90px; border-radius: 50%; background: #E9E4FF; }
.lead { margin: 0; font-size: 14px; color: var(--c-ink-2); line-height: 1.6; }
.hero .lead { font-size: 15px; }
.foot { margin: 12px 0 0; font-size: 12px; color: var(--c-muted); text-align: center; }
.head { display: flex; flex-direction: column; gap: 8px; }
.h { font-size: 26px; line-height: 1.35; }
.grow { flex: 1; }

.verify-btn { height: 64px; border: 1.5px solid var(--c-primary-line); border-radius: 18px; background: var(--c-surface); display: flex; align-items: center; gap: 12px; padding: 0 16px; text-align: left; }
.verify-ic { width: 40px; height: 40px; border-radius: 12px; background: var(--c-primary-soft); display: flex; align-items: center; justify-content: center; }
.verify-txt { flex: 1; display: flex; flex-direction: column; }
.verify-txt b { font-size: 15px; color: var(--c-ink); }
.verify-txt small { font-size: 12px; color: var(--c-muted); }

.verified { background: var(--c-surface); border-radius: 20px; padding: 16px; display: flex; flex-direction: column; gap: 12px; border: 2px solid var(--c-live); }
.ok { display: flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 700; color: var(--c-ok); }
.facts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.facts div { background: var(--c-bg); border-radius: 12px; padding: 10px 12px; display: flex; flex-direction: column; gap: 2px; }
.facts small { font-size: 12px; color: var(--c-muted); }
.facts b { font-size: 16px; }
.lock { display: flex; align-items: center; gap: 5px; font-size: 12px; color: var(--c-muted); }
.self-form { display: flex; flex-direction: column; gap: 16px; }
.adult { display: flex; gap: 10px; align-items: flex-start; font-size: 13px; line-height: 1.5; color: var(--c-ink-2); background: var(--c-surface); border-radius: 14px; padding: 12px 14px; }
.adult input { width: 20px; height: 20px; margin: 0; flex-shrink: 0; accent-color: var(--c-primary); }
.btn-primary.kakao { background: #FEE500; color: #191919; } /* 카카오 로그인 버튼 가이드 색 */
.note { font-size: 12px; color: var(--c-muted); line-height: 1.6; background: var(--c-surface); border-radius: 14px; padding: 12px 14px; }

.preview { align-self: center; width: 160px; height: 160px; border-radius: 80px; background: #E9E4FF; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.field { display: flex; flex-direction: column; gap: 8px; }
.label { font-size: 13px; font-weight: 700; color: var(--c-ink-2); }
.row { display: flex; gap: 10px; flex-wrap: wrap; }
.swatch { width: 44px; height: 44px; border-radius: 22px; border: 3px solid transparent; background: var(--c-surface); padding: 3px; }
.swatch.on { border-color: var(--c-primary); }
.swatch span { display: block; width: 100%; height: 100%; border-radius: 50%; }
.chip { height: 40px; padding: 0 16px; border: 1.5px solid var(--c-line); border-radius: 20px; background: var(--c-surface); color: var(--c-ink-2); font-size: 14px; font-weight: 500; }
.chip.on { border-color: var(--c-primary); background: var(--c-primary); color: #fff; font-weight: 700; }
.input { height: 50px; border: 1.5px solid var(--c-line); border-radius: 14px; padding: 0 14px; font-size: 15px; color: var(--c-ink); background: var(--c-surface); outline: none; }
.input:focus { border-color: var(--c-primary); }
.input[aria-invalid="true"] { border-color: var(--c-danger); }
.err { font-size: 12px; color: var(--c-danger); }

.photos { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
.photo { height: 140px; border-radius: 18px; border: 2px dashed #CFC6FF; background: var(--c-surface); display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; }
.photo.main { border-color: var(--c-primary); color: var(--c-primary-ink); font-size: 13px; font-weight: 700; }
.photo.filled { position: relative; border: none; overflow: hidden; }
.photo.filled img { width: 100%; height: 100%; object-fit: cover; }
.main-tag { position: absolute; left: 8px; top: 8px; background: #fff; color: var(--c-primary-deep); font-size: 11px; font-weight: 700; padding: 2px 7px; border-radius: 7px; }
.guide { display: flex; flex-direction: column; gap: 6px; background: var(--c-surface); border-radius: 14px; padding: 12px 14px; font-size: 12px; color: var(--c-ink-2); line-height: 1.6; }
.guide b { color: var(--c-ink); }

.dim { position: absolute; inset: 0; background: rgba(31, 27, 58, .45); display: flex; align-items: flex-end; z-index: 20; }
.sheet { width: 100%; background: var(--c-surface); border-radius: 28px 28px 0 0; padding: 10px 20px 30px; display: flex; flex-direction: column; gap: 14px; }
.grip { align-self: center; width: 40px; height: 5px; border-radius: 3px; background: var(--c-line); }
.muted { color: var(--c-muted); font-size: 12px; }
</style>
