/** 친구와의 1:1 대화방 열기 → 방 화면으로 이동 */
import { ApiError, post } from './api';
import { showToast } from './toast';

export async function openDm(router, userId) {
    try {
        const { room_id: roomId } = await post(`/dm/${userId}`);
        router.push({ name: 'dm', params: { roomId } });
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '대화방을 열지 못했어요');
    }
}
