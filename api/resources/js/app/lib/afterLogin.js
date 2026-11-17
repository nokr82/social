/**
 * 로그인 뒤 돌아갈 곳.
 * 공유 링크로 들어온 사람이 로그인·가입을 마치면 메인이 아니라 원래 보려던 광장·번개로 보낸다.
 * sessionStorage: 카카오 로그인으로 다른 사이트에 갔다 와도(같은 탭) 남고, 탭을 닫으면 사라진다.
 */
const KEY = 'social.afterLogin';

/** 앱 안 경로만 허용 ('//다른사이트'·로그인 화면 자체는 거름 → 열린 리다이렉트 방지) */
function isSafe(path) {
    return typeof path === 'string'
        && path.startsWith('/') && !path.startsWith('//')
        && !path.startsWith('/onboarding') && !path.startsWith('/auth');
}

export function rememberAfterLogin(path) {
    try {
        if (isSafe(path) && path !== '/') sessionStorage.setItem(KEY, path);
    } catch { /* 사생활 보호 모드 등 저장 불가 → 메인으로 가면 됨 */ }
}

/** 꺼내면서 지운다 (한 번만 쓰임). 없으면 null */
export function takeAfterLogin() {
    try {
        const path = sessionStorage.getItem(KEY);
        sessionStorage.removeItem(KEY);
        return isSafe(path) ? path : null;
    } catch {
        return null;
    }
}
