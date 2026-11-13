<script setup>
/**
 * 폰 화면 프레임 + 라우터 + 토스트. 데스크톱에서는 가운데 390px 폭으로 보여준다
 * 로그인 상태가 되면 알림(종 숫자·실시간)과 내 위치 공유를 시작하고, 푸시 알림을 누르면 해당 화면으로 이동
 * 위치를 공유 중이면 모든 화면에 '위치 공유 중' 표시 (모르는 사이 공유되는 일이 없게, 누르면 친구 지도)
 */
import { onBeforeUnmount, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { auth } from './lib/auth';
import { locShare, startLocationShare, stopLocationShare } from './lib/locationShare';
import { startNotifications, stopNotifications } from './lib/notify';
import { registerServiceWorker } from './lib/push';
import { toast } from './lib/toast';

const router = useRouter();
const route = useRoute();

watch(() => auth.me?.id, (id) => {
    if (id) {
        startNotifications(auth.me);
        startLocationShare();
    } else {
        stopNotifications();
        stopLocationShare();
    }
}, { immediate: true });

// 서비스 워커가 '알림 눌림 → 이 화면으로' 요청을 보냄
function onSwMessage(e) {
    if (e.data?.type === 'navigate' && e.data.url) router.push(e.data.url);
}
onMounted(() => {
    registerServiceWorker();
    navigator.serviceWorker?.addEventListener('message', onSwMessage);
});
onBeforeUnmount(() => navigator.serviceWorker?.removeEventListener('message', onSwMessage));
</script>

<template>
    <div class="phone">
        <RouterView />
        <button v-if="locShare.shares.length && route.name !== 'map'" class="loc-pill" @click="router.push({ name: 'map' })">
            📍 위치 공유 중 · {{ locShare.shares.length }}명
        </button>
        <Transition name="fade">
            <div v-if="toast.text" class="toast" role="status">{{ toast.text }}</div>
        </Transition>
    </div>
</template>

<style scoped>
.phone {
    position: relative; margin: 0 auto; width: 100%; max-width: 430px;
    height: 100dvh; background: var(--c-bg); overflow: hidden;
}
@media (min-width: 600px) {
    /* 데스크톱 확인용: 디자인 시안 크기(390x844)의 둥근 프레임 */
    .phone { width: 390px; height: min(844px, 100dvh); margin-top: max(0px, calc((100dvh - 844px) / 2)); border-radius: 32px; box-shadow: 0 20px 60px rgba(31,27,58,.18); }
}
.toast {
    position: absolute; left: 20px; right: 20px; bottom: 100px; z-index: 50;
    background: rgba(31, 27, 58, 0.88); color: #fff; border-radius: 14px; padding: 12px 16px;
    font-size: 14px; text-align: center;
}
.loc-pill {
    position: absolute; left: 12px; bottom: 92px; z-index: 40; height: 30px; padding: 0 12px;
    border: none; border-radius: 15px; background: rgba(30, 138, 85, 0.92); color: #fff;
    font-size: 12px; font-weight: 700; box-shadow: 0 2px 8px rgba(31, 27, 58, .2);
}
.fade-enter-active, .fade-leave-active { transition: opacity .2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
