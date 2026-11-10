/**
 * 현재 위치. 좌표는 요청에만 쓰고 서버는 저장하지 않는다(거리 라벨 계산용).
 * 권한 거부·실패 시 기본 위치(역삼역)로 대체하고 그 사실을 화면에 알린다.
 */
const FALLBACK = { lat: 37.5006, lng: 127.0364, label: '역삼동', fallback: true };
let cached = null;

export function getLocation() {
    if (cached) return Promise.resolve(cached);

    return new Promise((resolve) => {
        if (!navigator.geolocation) return resolve((cached = FALLBACK));

        navigator.geolocation.getCurrentPosition(
            (pos) => resolve((cached = {
                lat: pos.coords.latitude,
                lng: pos.coords.longitude,
                // 동 이름은 역지오코딩(카카오 로컬 API 등) 연동 전까지 '내 주변'
                label: '내 주변',
                fallback: false,
            })),
            () => resolve((cached = FALLBACK)),
            { enableHighAccuracy: false, timeout: 5000, maximumAge: 5 * 60 * 1000 },
        );
    });
}
