/**
 * 카카오맵 JavaScript SDK 불러오기 (친구 지도 화면에 들어갈 때 한 번만).
 * - 키는 서버(/api/meta/map)에서 받는다 → 키를 바꿔도 프런트 재빌드 불필요
 * - 키가 없거나 불러오기 실패(도메인 미등록·카카오맵 미사용 설정)면 null → 화면은 목록만 보여준다
 */
import { get } from './api';

let loading = null;

export function loadKakaoMaps() {
    if (window.kakao?.maps?.LatLng) return Promise.resolve(window.kakao);
    if (loading) return loading;

    loading = (async () => {
        const { kakao_js_key: key } = await get('/meta/map');
        if (!key) return null;

        await new Promise((resolve, reject) => {
            const s = document.createElement('script');
            // autoload=false: 스크립트를 받은 뒤 kakao.maps.load()로 직접 초기화 (동적 삽입에 필요)
            s.src = `https://dapi.kakao.com/v2/maps/sdk.js?appkey=${encodeURIComponent(key)}&autoload=false`;
            s.onload = resolve;
            s.onerror = reject;
            document.head.appendChild(s);
        });
        await new Promise((resolve) => window.kakao.maps.load(resolve));

        return window.kakao;
    })().catch(() => {
        loading = null; // 다음에 다시 시도할 수 있게
        return null;
    });

    return loading;
}
