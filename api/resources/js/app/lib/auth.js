/**
 * 로그인 상태 (토큰 + 내 정보). reactive라 화면이 자동 갱신된다.
 * 토큰은 localStorage에 보관 — 앱으로 감쌀 때는 안전한 저장소(Keychain 등)로 교체.
 */
import { reactive } from 'vue';

const KEY = 'social.token';

function readToken() {
    try { return localStorage.getItem(KEY); } catch { return null; }
}

export const auth = reactive({
    token: readToken(),
    me: null,

    setToken(token) {
        this.token = token;
        try { localStorage.setItem(KEY, token); } catch { /* 사파리 사생활 보호 모드 등 */ }
    },

    clear() {
        this.token = null;
        this.me = null;
        try { localStorage.removeItem(KEY); } catch { /* 무시 */ }
    },

    get loggedIn() {
        return !!this.token;
    },
});
