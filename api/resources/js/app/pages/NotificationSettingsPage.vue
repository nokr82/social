<script setup>
/**
 * MY > 알림 설정
 * - 이 기기 푸시 켜기/끄기 (권한 요청은 버튼을 눌렀을 때만)
 * - 종류별 푸시 켬/끔 + 밤 10시~아침 8시 무음 (알림센터에는 항상 쌓임)
 * - 푸시를 못 쓰는 환경이면 이유와 방법을 안내 (HTTP, iPhone 홈 화면 미설치, 권한 거절)
 */
import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { ApiError, get, put } from '../lib/api';
import { disablePush, enablePush, isPushOn, pushSupport } from '../lib/push';
import { showToast } from '../lib/toast';

const router = useRouter();
const settings = reactive({ friend: true, chat: true, meetup: true, night_quiet: false, push_devices: 0 });
const pushOn = ref(false);
const busy = ref(false);
const support = ref(pushSupport());
const denied = ref(typeof Notification !== 'undefined' && Notification.permission === 'denied');

const ROWS = [
    { key: 'friend', label: '친구', desc: '친구 요청 · 수락' },
    { key: 'chat', label: '채팅', desc: '1:1 대화 · 번개방 새 메시지' },
    { key: 'meetup', label: '번개', desc: '내 번개 참여 · 취소 · 1시간 전' },
    { key: 'night_quiet', label: '야간 무음', desc: '밤 10시 ~ 아침 8시에는 푸시를 보내지 않아요' },
];

const SUPPORT_MSG = {
    insecure: '보안 연결(HTTPS)에서만 푸시를 쓸 수 있어요. 정식 주소에서 다시 열어 주세요.',
    'ios-install': 'iPhone은 Safari 아래쪽 공유 버튼 → "홈 화면에 추가"로 설치한 뒤, 홈 화면의 소셜링 앱에서 켤 수 있어요.',
    unsupported: '이 브라우저는 푸시 알림을 지원하지 않아요.',
};

onMounted(async () => {
    try {
        Object.assign(settings, (await get('/me/notification-settings')).data);
        pushOn.value = await isPushOn();
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '불러오지 못했어요');
    }
});

async function togglePush() {
    if (busy.value) return;
    busy.value = true;
    try {
        if (pushOn.value) {
            await disablePush();
            pushOn.value = false;
            showToast('이 기기의 푸시를 껐어요');
        } else {
            const r = await enablePush();
            if (r === 'on') {
                pushOn.value = true;
                showToast('이제 앱을 닫아도 알림이 와요');
            } else if (r === 'denied') {
                denied.value = true;
            } else if (r === 'no-key') {
                showToast('서버 푸시 설정이 아직 안 됐어요');
            } else {
                support.value = r;
            }
        }
    } catch {
        showToast('푸시 설정에 실패했어요. 잠시 후 다시 시도해 주세요');
    } finally {
        busy.value = false;
    }
}

async function toggle(key) {
    const prev = settings[key];
    settings[key] = !prev; // 먼저 바꿔 보여주고 실패하면 되돌림
    try {
        await put('/me/notification-settings', { [key]: settings[key] });
    } catch (e) {
        settings[key] = prev;
        showToast(e instanceof ApiError ? e.message : '저장하지 못했어요');
    }
}
</script>

<template>
    <div class="ns">
        <div class="head">
            <button class="icon" aria-label="뒤로가기" @click="router.back()">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
            </button>
            <h1 class="title" style="font-size: 22px">알림 설정</h1>
        </div>

        <div class="body scroll">
            <!-- 이 기기 푸시 -->
            <div class="card">
                <div class="row">
                    <span class="col">
                        <b>이 기기에서 푸시 받기</b>
                        <small>앱을 닫아도 폰 알림으로 받아요</small>
                    </span>
                    <button class="switch" role="switch" :aria-checked="pushOn" :class="{ on: pushOn }"
                            :disabled="busy || support !== 'ok' || (denied && !pushOn)" aria-label="이 기기에서 푸시 받기" @click="togglePush">
                        <span />
                    </button>
                </div>
                <p v-if="support !== 'ok'" class="guide">{{ SUPPORT_MSG[support] }}</p>
                <p v-else-if="denied && !pushOn" class="guide">알림 권한이 거절돼 있어요. 브라우저(또는 폰) 설정 → 사이트 설정 → 알림에서 허용으로 바꿔 주세요.</p>
            </div>

            <!-- 종류별 -->
            <div class="card">
                <div v-for="r in ROWS" :key="r.key" class="row">
                    <span class="col">
                        <b>{{ r.label }}</b>
                        <small>{{ r.desc }}</small>
                    </span>
                    <button class="switch" role="switch" :aria-checked="settings[r.key]" :class="{ on: settings[r.key] }" :aria-label="r.label" @click="toggle(r.key)">
                        <span />
                    </button>
                </div>
            </div>
            <p class="note">여기서 끈 알림도 앱 안 알림(종 아이콘)에는 그대로 쌓여요.</p>
        </div>
    </div>
</template>

<style scoped>
.ns { height: 100%; display: flex; flex-direction: column; }
.head { display: flex; align-items: center; gap: 4px; padding: 14px 12px 6px 8px; }
.icon { width: 44px; height: 44px; border: none; background: none; display: flex; align-items: center; justify-content: center; }
.body { flex: 1; min-height: 0; padding: 12px 20px 32px; display: flex; flex-direction: column; gap: 14px; }
.card { background: var(--c-surface); border-radius: 20px; padding: 4px 16px; }
.row { display: flex; align-items: center; gap: 12px; min-height: 64px; border-bottom: 1px solid var(--c-primary-soft); }
.row:last-child { border-bottom: none; }
.col { flex: 1; display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.col b { font-size: 15px; color: var(--c-ink); }
.col small { font-size: 12px; color: var(--c-muted); line-height: 1.4; }
.switch { width: 52px; height: 32px; border: none; border-radius: 16px; background: #DAD5EE; padding: 3px; display: flex; flex-shrink: 0; transition: background .2s; }
.switch span { width: 26px; height: 26px; border-radius: 13px; background: #fff; box-shadow: 0 1px 3px rgba(31,27,58,.2); transition: transform .2s; }
.switch.on { background: var(--c-primary); }
.switch.on span { transform: translateX(20px); }
.switch:disabled { opacity: .5; }
.guide { margin: 0 0 12px; font-size: 13px; line-height: 1.5; color: var(--c-primary-deep); background: var(--c-bg); border-radius: 12px; padding: 10px 12px; }
.note { margin: 0; font-size: 12px; color: var(--c-muted); text-align: center; }
</style>
