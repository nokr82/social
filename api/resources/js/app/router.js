import { createRouter, createWebHistory } from 'vue-router';
import { auth } from './lib/auth';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/', name: 'main', component: () => import('./pages/MainPage.vue') },
        { path: '/onboarding', name: 'onboarding', component: () => import('./pages/OnboardingPage.vue'), meta: { public: true } },
        { path: '/plaza/:id(\\d+)', name: 'plaza', component: () => import('./pages/PlazaPage.vue'), props: true },
        // 동네 상시 광장 (구역·채널)
        { path: '/town/:id(\\d+)', name: 'town', component: () => import('./pages/TownPage.vue'), props: true },
        // 신고·차단: 대상 정보는 query로 (nickname, color, from=plaza id)
        { path: '/report/:userId(\\d+)', name: 'report', component: () => import('./pages/ReportPage.vue'), props: true },
        { path: '/my', name: 'my', component: () => import('./pages/MyPage.vue') },
        { path: '/notifications', name: 'notifications', component: () => import('./pages/NotificationsPage.vue') },
        { path: '/my/notifications', name: 'notification-settings', component: () => import('./pages/NotificationSettingsPage.vue') },
        { path: '/my/blocks', name: 'blocks', component: () => import('./pages/BlockListPage.vue') },
        { path: '/friends/requests', name: 'friend-requests', component: () => import('./pages/FriendRequestsPage.vue') },
        // 상대 프로필: from=광장 id (친구 요청 '어디서 만났는지'에 사용)
        { path: '/users/:id(\\d+)', name: 'profile', component: () => import('./pages/ProfilePage.vue'), props: true },
        { path: '/chats', name: 'chats', component: () => import('./pages/ChatTabPage.vue') },
        { path: '/chats/:roomId(\\d+)', name: 'dm', component: () => import('./pages/DmRoomPage.vue'), props: true },
        { path: '/meetups', name: 'meetups', component: () => import('./pages/MeetupTabPage.vue') },
        { path: '/meetups/:id(\\d+)', name: 'meetup', component: () => import('./pages/MeetupDetailPage.vue'), props: true },
        { path: '/meetups/:id(\\d+)/room', name: 'meetup-room', component: () => import('./pages/MeetupRoomPage.vue'), props: true },
        { path: '/plazas/:plazaId(\\d+)/meetups/new', name: 'meetup-create', component: () => import('./pages/MeetupCreatePage.vue'), props: true },
        { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
});

// 로그인 안 했으면 온보딩으로
router.beforeEach((to) => {
    if (!to.meta.public && !auth.loggedIn) return { name: 'onboarding' };
});

export default router;
