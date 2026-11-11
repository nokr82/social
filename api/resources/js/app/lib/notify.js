/**
 * 알림센터 상태 (종 아이콘 숫자) + 실시간 수신.
 * 로그인 후 App.vue가 한 번 start()를 부르고, 로그아웃 시 stop().
 *
 * 개인 채널(App.Models.User.{id})은 채팅 탭도 같이 쓰므로, 여기서는 채널을 leave하지 않고
 * 알림 이벤트 구독만 붙였다 뗀다 (leave하면 다른 화면의 구독까지 끊김).
 */
import { reactive } from 'vue';
import { connectEcho } from './echo';
import { showToast } from './toast';

export const notify = reactive({ unread: 0 });

const NOTIFICATION_EVENT = '.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated';
let channelName = null;

export function startNotifications(me) {
    notify.unread = me.stats?.unread_notifications ?? 0;
    if (channelName) return;

    channelName = `App.Models.User.${me.id}`;
    connectEcho().private(channelName).notification((n) => {
        notify.unread++;
        showToast(n.body ?? n.title);
    });
}

export function stopNotifications() {
    if (channelName && window.Echo) {
        window.Echo.private(channelName).stopListening(NOTIFICATION_EVENT);
    }
    channelName = null;
    notify.unread = 0;
}
