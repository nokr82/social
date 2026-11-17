/**
 * 광장·번개 공유.
 * 서버에서 링크(/s/p/…, /s/m/…)를 받아 휴대폰 기본 공유창(Web Share)을 연다 → 카카오톡 등을 고르면
 * 카카오가 미리보기 페이지의 og 태그로 카드를 만든다. 카카오 SDK·키가 필요 없어 고장 날 곳이 적다.
 * 공유창이 없는 환경(PC 브라우저 일부)은 링크를 복사한다.
 */
import { ApiError, post } from './api';
import { showToast } from './toast';

/** @param {string} path '/plazas/{id}/share' 또는 '/meetups/{id}/share' */
export async function shareLink(path) {
    let data;
    try {
        ({ data } = await post(path));
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '링크를 만들지 못했어요');
        return;
    }

    if (navigator.share) {
        try {
            await navigator.share({ title: data.title, text: data.text, url: data.url });
            return;
        } catch (e) {
            if (e?.name === 'AbortError') return; // 사용자가 공유창을 닫음
            // 그 밖의 실패(권한 등)는 아래 복사로
        }
    }
    await copyText(data.url);
    showToast('링크를 복사했어요. 카카오톡에 붙여 넣어 보내세요', 3000);
}

/** 클립보드 복사. https가 아니면(개발 VM을 IP로 접속) clipboard API가 막혀 예전 방식으로 */
async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
    }
}
