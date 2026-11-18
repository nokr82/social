/**
 * 카카오맵 열기: 휴대폰에 카카오맵 앱이 있으면 앱으로, 없으면 웹(카카오맵 사이트)으로.
 *
 * 왜 기기마다 다르게 하나:
 * - Android: intent:// 주소를 쓰면 크롬이 '앱 있으면 앱, 없으면 browser_fallback_url'을 알아서 처리 (가장 확실)
 * - iOS: 앱 설치 여부를 웹에서 알 방법이 없음 → 앱 주소(kakaomap://)로 먼저 시도하고,
 *   1.5초 안에 화면이 숨겨지지 않으면(=앱이 안 열림) 웹으로 이동.
 *   한계: 앱이 없으면 Safari가 '주소가 유효하지 않음' 창을 잠깐 띄울 수 있음
 * - PC: 앱이 없으므로 바로 웹을 새 탭으로
 *
 * 앱 주소 (카카오맵 URL Scheme):
 *   kakaomap://place?id={장소id}   장소 상세(길찾기 버튼 포함)
 *   kakaomap://look?p={lat},{lng}  좌표 보기 (핀으로 정한 장소)
 *   kakaomap://search?q={검색어}   검색 (글로만 적은 장소)
 */
const ANDROID_PACKAGE = 'net.daum.android.map';

function platform() {
    const ua = navigator.userAgent;
    if (/Android/i.test(ua)) return 'android';
    // iPadOS 13+는 데스크톱(Mac)처럼 보이므로 터치 지원으로 구분
    if (/iPhone|iPad|iPod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)) return 'ios';
    return 'desktop';
}

/**
 * 번개 장소(place: {name, lat, lng, kakao_id, map_url}) 또는 검색어 → 앱 주소·웹 주소
 */
export function kakaoMapLinks({ place = null, query = null }) {
    if (place?.kakao_id) {
        return { app: `kakaomap://place?id=${encodeURIComponent(place.kakao_id)}`, web: place.map_url };
    }
    if (place) {
        return { app: `kakaomap://look?p=${place.lat},${place.lng}`, web: place.map_url };
    }
    return {
        app: `kakaomap://search?q=${encodeURIComponent(query)}`,
        web: `https://map.kakao.com/?q=${encodeURIComponent(query)}`,
    };
}

export function openKakaoMap({ app, web }) {
    const p = platform();

    if (p === 'desktop') {
        window.open(web, '_blank', 'noopener');
        return;
    }

    if (p === 'android') {
        const [scheme, rest] = app.split('://');
        window.location.href = `intent://${rest}#Intent;scheme=${scheme};package=${ANDROID_PACKAGE};`
            + `S.browser_fallback_url=${encodeURIComponent(web)};end`;
        return;
    }

    // iOS: 앱으로 넘어가면 화면이 숨겨짐 → 그 경우엔 웹으로 보내지 않음
    let left = false;
    const onHide = () => { if (document.hidden) left = true; };
    document.addEventListener('visibilitychange', onHide);
    window.addEventListener('pagehide', onHide);
    setTimeout(() => {
        document.removeEventListener('visibilitychange', onHide);
        window.removeEventListener('pagehide', onHide);
        if (!left && !document.hidden) window.location.href = web;
    }, 1500);
    window.location.href = app;
}
