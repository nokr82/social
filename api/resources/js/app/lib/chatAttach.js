/**
 * 채팅 사진 보내기 공통: 브라우저에서 1600px로 줄인 뒤 업로드
 * @param {string} path  '/chats/{room}/images' | '/meetups/{id}/images'
 */
import { upload } from './api';
import { shrinkImage } from './image';

export async function sendChatImage(path, file) {
    const form = new FormData();
    form.append('image', await shrinkImage(file));
    return (await upload(path, form)).data;
}

/** 실시간으로 받은 메시지의 목록용 한 줄 요약 (서버 ChatMessage::preview()와 같은 규칙) */
export function previewOf(msg) {
    if (msg.type === 'image') return '사진을 보냈어요';
    if (msg.type === 'meetup') return `번개를 공유했어요: ${msg.body}`;
    return msg.body;
}
