<script setup>
/** 폰 화면 프레임 + 라우터 + 토스트. 데스크톱에서는 가운데 390px 폭으로 보여준다 */
import { toast } from './lib/toast';
</script>

<template>
    <div class="phone">
        <RouterView />
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
.fade-enter-active, .fade-leave-active { transition: opacity .2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
