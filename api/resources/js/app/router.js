import { createRouter, createWebHistory } from 'vue-router';
import { auth } from './lib/auth';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/', name: 'main', component: () => import('./pages/MainPage.vue') },
        { path: '/onboarding', name: 'onboarding', component: () => import('./pages/OnboardingPage.vue'), meta: { public: true } },
        { path: '/plaza/:id(\\d+)', name: 'plaza', component: () => import('./pages/PlazaPage.vue'), props: true },
        // 신고·차단: 대상 정보는 query로 (nickname, color, from=plaza id)
        { path: '/report/:userId(\\d+)', name: 'report', component: () => import('./pages/ReportPage.vue'), props: true },
        { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
});

// 로그인 안 했으면 온보딩으로
router.beforeEach((to) => {
    if (!to.meta.public && !auth.loggedIn) return { name: 'onboarding' };
});

export default router;
